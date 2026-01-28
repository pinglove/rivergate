<?php

namespace App\Console\Commands\Amazon\Asins;

use App\Models\Amazon\Asins\AsinUserMpSync;
use App\Models\Amazon\Asins\AsinUserMpSyncLog;
use App\Models\Amazon\RefreshToken;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class SyncCatalogWorkerCommand extends Command
{
    protected $signature = 'asins:sync-catalog-worker
        {--once : Process at most one job and exit}
        {--debug : Print detailed debug output to console}
        {--sleep=5 : Idle sleep seconds when no jobs (non-once mode)}';

    protected $description = 'ASIN catalog sync worker (request report -> poll -> download -> import -> completed)';

    private const COOLDOWN_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;

    private const STEP_WORKER_STARTED   = 10;
    private const STEP_REPORT_REQUESTED = 20;
    private const STEP_REPORT_DOWNLOADED= 30;
    private const STEP_IMPORTED         = 40;
    private const STEP_RETRY            = 90;
    private const STEP_ERROR            = 99;

    public function handle(): int
    {
        $once      = (bool) $this->option('once');
        $debug     = (bool) $this->option('debug');
        $idleSleep = max(1, (int) $this->option('sleep'));

        $this->line('=== ASINS SYNC WORKER v2026-01-22 ===');

        if ($debug) {
            $this->line('DEBUG: once=' . ($once ? 'YES' : 'NO'));
            $this->line('DEBUG: COOLDOWN_SECONDS=' . self::COOLDOWN_SECONDS);
            $this->line('DEBUG: idle_sleep=' . $idleSleep);
        }

        while (true) {
            $dbNow = $this->dbNow();

            if ($debug) {
                $this->line('DEBUG: db_now=' . $dbNow->format('Y-m-d H:i:s'));
            }

            $job = $this->pickNextJob($dbNow, $debug);

            if (! $job) {
                if ($debug) {
                    $this->warn('DEBUG: no eligible jobs');
                }

                if ($once) {
                    return self::SUCCESS;
                }

                sleep($idleSleep);
                continue;
            }

            if ($debug) {
                $this->line("DEBUG: processing sync_id={$job->id} status={$job->status} attempts={$job->attempts}");
            }

            $this->processJob($job, $dbNow, $debug);

            if ($once) {
                return self::SUCCESS;
            }

            usleep(200_000);
        }
    }

    private function dbNow(): Carbon
    {
        $row = DB::selectOne('SELECT NOW() AS now');
        return Carbon::parse($row->now);
    }

    private function pickNextJob(Carbon $dbNow, bool $debug): ?AsinUserMpSync
    {
        $eligible = [
            'pending',
            'worker_started',
            'worker_fetching',
            'worker_fetched',
            'error',
        ];

        $jobs = AsinUserMpSync::query()
            ->whereIn('status', $eligible)
            ->orderByRaw("FIELD(status,'pending','worker_started','worker_fetching','worker_fetched','error')")
            ->orderBy('updated_at')
            ->limit(50)
            ->get();

        if ($debug) {
            $this->line('DEBUG: candidate jobs=' . $jobs->count());
        }

        foreach ($jobs as $job) {
            $lastAt = $this->getLastActivityAt($job->id) ?? $job->updated_at;
            $ageSec = $this->ageSeconds($lastAt, $dbNow);

            if ($debug) {
                $this->line("DEBUG: sync_id={$job->id} status={$job->status} last={$lastAt} age={$ageSec}s");
            }

            if ($ageSec < self::COOLDOWN_SECONDS) {
                continue;
            }

            return $job;
        }

        return null;
    }

    private function ageSeconds($lastAt, Carbon $dbNow): int
    {
        try {
            $last = Carbon::parse($lastAt);
            $diff = $last->diffInSeconds($dbNow, false);
            return $diff < 0 ? 0 : (int) $diff;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getLastActivityAt(int $syncId): ?string
    {
        return AsinUserMpSyncLog::query()
            ->where('sync_id', $syncId)
            ->orderByDesc('id')
            ->value('created_at');
    }

    private function buildAuthArgs(RefreshToken $auth): array
    {
        return [
            '--lwa_refresh_token=' . $auth->lwa_refresh_token,
            '--lwa_client_id=' . $auth->lwa_client_id,
            '--lwa_client_secret=' . $auth->lwa_client_secret,
            '--aws_access_key_id=' . $auth->aws_access_key_id,
            '--aws_secret_access_key=' . $auth->aws_secret_access_key,
            '--aws_role_arn=' . $auth->aws_role_arn,
            '--sp_api_region=' . ($auth->sp_api_region ?? 'eu'),
        ];
    }

    private function processJob(AsinUserMpSync $job, Carbon $dbNow, bool $debug): void
    {
        DB::beginTransaction();

        try {
            $locked = AsinUserMpSync::query()
                ->where('id', $job->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                DB::rollBack();
                return;
            }

            $lastAt = $this->getLastActivityAt($locked->id) ?? $locked->updated_at;
            $ageSec = $this->ageSeconds($lastAt, $dbNow);

            if ($debug) {
                $this->line("DEBUG: locked sync_id={$locked->id} status={$locked->status} last={$lastAt} age={$ageSec}s");
            }

            if ($ageSec < self::COOLDOWN_SECONDS) {
                DB::commit();
                return;
            }

            /* pending → worker_started */
            if ($locked->status === 'pending') {
                $this->transition($locked, 'worker_started', self::STEP_WORKER_STARTED, [
                    'msg' => 'Worker started',
                ], $debug);

                DB::commit();
                return;
            }

            /* worker_started → worker_fetching */
            if ($locked->status === 'worker_started') {

                $amazonMarketplaceId = DB::table('marketplaces')
                    ->where('id', (int) $locked->marketplace_id)
                    ->value('amazon_id');

                if (! $amazonMarketplaceId) {
                    throw new \RuntimeException("marketplaces.amazon_id is NULL for marketplace_id={$locked->marketplace_id}");
                }

                $auth = RefreshToken::query()
                    ->where('user_id', $locked->user_id)
                    ->where('marketplace_id', $locked->marketplace_id)
                    ->where('status', 'active')
                    ->first();

                if (! $auth) {
                    throw new \RuntimeException('Active refresh token not found');
                }

                $cmd = array_merge(
                    [
                        'node',
                        'spapi/asins/requestCatalogReport.js',
                        '--marketplace_id=' . $amazonMarketplaceId,
                    ],
                    $this->buildAuthArgs($auth)
                );

                if ($debug) {
                    $this->line('DEBUG CMD: ' . implode(' ', $cmd));
                }

                $res = Process::timeout(600)->run($cmd);

                if ($debug) {
                    $this->line('DEBUG STDOUT:');
                    $this->line($res->output());
                    $this->line('DEBUG STDERR:');
                    $this->line($res->errorOutput());
                }

                $json = $this->extractLastJsonObject($res->output());
                $reportId = data_get($json, 'data.report_id');

                if (! $res->successful() || empty($json['success']) || ! $reportId) {
                    $this->logStep($locked->id, self::STEP_ERROR, [
                        'msg' => 'requestCatalogReport failed',
                        'stdout' => $res->output(),
                        'stderr' => $res->errorOutput(),
                    ], $debug);

                    $locked->status = 'error';
                    $locked->updated_at = now();
                    $locked->save();

                    DB::commit();
                    return;
                }

                $auth->markUsed();

                $this->logStep($locked->id, self::STEP_REPORT_REQUESTED, [
                    'msg' => 'Catalog report requested',
                    'report_id' => $reportId,
                ], $debug);

                $locked->status = 'worker_fetching';
                $locked->started_at ??= now();
                $locked->updated_at = now();
                $locked->save();

                DB::commit();
                return;
            }

            /* worker_fetching → worker_fetched */
            if ($locked->status === 'worker_fetching') {

                $reportId = $this->getLastReportIdFromLogs($locked->id);
                if (! $reportId) {
                    throw new \RuntimeException('report_id not found in logs');
                }

                $auth = RefreshToken::query()
                    ->where('user_id', $locked->user_id)
                    ->where('marketplace_id', $locked->marketplace_id)
                    ->where('status', 'active')
                    ->first();

                if (! $auth) {
                    throw new \RuntimeException('Active refresh token not found (poll)');
                }

                $cmd = array_merge(
                    [
                        'node',
                        'spapi/asins/pollCatalogReport.js',
                        '--report_id=' . $reportId,
                    ],
                    $this->buildAuthArgs($auth)
                );

                if ($debug) {
                    $this->line('DEBUG CMD: ' . implode(' ', $cmd));
                }

                $res = Process::timeout(900)->run($cmd);

                if ($debug) {
                    $this->line('DEBUG STDOUT:');
                    $this->line($res->output());
                    $this->line('DEBUG STDERR:');
                    $this->line($res->errorOutput());
                }

                $json = $this->extractLastJsonObject($res->output());
                $file = data_get($json, 'file');

                if (! $res->successful() || empty($json['success']) || ! $file) {
                    $this->logStep($locked->id, self::STEP_ERROR, [
                        'msg' => 'pollCatalogReport failed',
                        'stdout' => $res->output(),
                        'stderr' => $res->errorOutput(),
                    ], $debug);

                    $locked->status = 'error';
                    $locked->updated_at = now();
                    $locked->save();

                    DB::commit();
                    return;
                }

                $this->logStep($locked->id, self::STEP_REPORT_DOWNLOADED, [
                    'msg' => 'Catalog report downloaded',
                    'report_id' => $reportId,
                    'file' => $file,
                ], $debug);

                $locked->status = 'worker_fetched';
                $locked->updated_at = now();
                $locked->save();

                DB::commit();
                return;
            }

            /* worker_fetched → import → completed */
            if ($locked->status === 'worker_fetched') {

                $file = $this->getLastDownloadedFileFromLogs($locked->id);
                if (! $file) {
                    throw new \RuntimeException('downloaded file not found');
                }

                $cmd = [
                    'node',
                    'spapi/asins/importCatalog.js',
                    '--file=' . $file,
                    '--user_id=' . (int) $locked->user_id,
                    '--marketplace_id=' . (int) $locked->marketplace_id,
                ];

                if ($debug) {
                    $this->line('DEBUG CMD: ' . implode(' ', $cmd));
                }

                $res = Process::timeout(3600)->run($cmd);

                if ($debug) {
                    $this->line('DEBUG STDOUT:');
                    $this->line($res->output());
                    $this->line('DEBUG STDERR:');
                    $this->line($res->errorOutput());
                }

                $json = $this->extractLastJsonObject($res->output());

                if (! $res->successful() || empty($json['success'])) {
                    $this->logStep($locked->id, self::STEP_ERROR, [
                        'msg' => 'importCatalog failed',
                        'stdout' => $res->output(),
                        'stderr' => $res->errorOutput(),
                    ], $debug);

                    $locked->status = 'error';
                    $locked->updated_at = now();
                    $locked->save();

                    DB::commit();
                    return;
                }

                $this->logStep($locked->id, self::STEP_IMPORTED, [
                    'msg' => 'Catalog imported',
                    'file' => $file,
                ], $debug);

                $locked->status = 'completed';
                $locked->finished_at = now();
                $locked->updated_at = now();
                $locked->save();

                DB::commit();
                return;
            }

            /* error → retry */
            if ($locked->status === 'error') {
                if ($locked->attempts >= self::MAX_ATTEMPTS) {
                    DB::commit();
                    return;
                }

                $locked->attempts++;
                $locked->status = 'worker_started';
                $locked->updated_at = now();
                $locked->save();

                $this->logStep($locked->id, self::STEP_RETRY, [
                    'msg' => 'Retry after error',
                    'attempts' => $locked->attempts,
                ], $debug);

                DB::commit();
                return;
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            AsinUserMpSync::query()
                ->where('id', $job->id)
                ->update([
                    'status' => 'error',
                    'updated_at' => now(),
                ]);

            $this->logStep($job->id, self::STEP_ERROR, [
                'msg' => 'Worker exception',
                'error' => $e->getMessage(),
            ], $debug);
        }
    }

    private function transition(AsinUserMpSync $job, string $newStatus, int $pipelineStep, array $payload, bool $debug): void
    {
        $job->status = $newStatus;
        $job->started_at ??= now();
        if (in_array($newStatus, ['completed','error','cancelled'], true)) {
            $job->finished_at = now();
        }
        $job->updated_at = now();
        $job->save();

        $this->logStep($job->id, $pipelineStep, $payload, $debug);
    }

    private function logStep(int $syncId, int $pipelineStep, array $payload, bool $debug): void
    {
        AsinUserMpSyncLog::create([
            'sync_id'       => $syncId,
            'pipeline_step' => $pipelineStep,
            'payload'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at'    => now(),
        ]);
    }

    private function getLastReportIdFromLogs(int $syncId): ?string
    {
        $log = AsinUserMpSyncLog::where('sync_id', $syncId)
            ->where('pipeline_step', self::STEP_REPORT_REQUESTED)
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            return null;
        }

        $payload = $this->decodePayload($log->payload);
        return data_get($payload, 'report_id');
    }

    private function getLastDownloadedFileFromLogs(int $syncId): ?string
    {
        $log = AsinUserMpSyncLog::where('sync_id', $syncId)
            ->where('pipeline_step', self::STEP_REPORT_DOWNLOADED)
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            return null;
        }

        $payload = $this->decodePayload($log->payload);
        return data_get($payload, 'file');
    }

    private function decodePayload($payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $a = json_decode($payload, true);
        if (is_array($a)) {
            return $a;
        }

        $trim = trim($payload);
        if (str_starts_with($trim, '"') && str_ends_with($trim, '"')) {
            $unescaped = stripcslashes(trim($trim, '"'));
            $b = json_decode($unescaped, true);
            if (is_array($b)) {
                return $b;
            }
        }

        return [];
    }

    private function extractLastJsonObject(string $stdout): array
    {
        if ($stdout === '') {
            return [];
        }

        $lines = array_reverse(preg_split('/\R/', trim($stdout)));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{' && str_ends_with($line, '}')) {
                $json = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $json;
                }
            }
        }

        return [];
    }
}
