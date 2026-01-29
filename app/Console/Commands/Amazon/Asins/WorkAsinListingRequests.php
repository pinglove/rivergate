<?php

namespace App\Console\Commands\Amazon\Asins;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Amazon\Asins\AsinListingSyncRequest;
use App\Models\Amazon\Asins\AsinListingSyncRequestPayload;
use App\Models\Amazon\RefreshToken;

class WorkAsinListingRequests extends Command
{
    protected $signature = 'asins:work-listing-requests
        {--sleep=5 : Idle sleep seconds}
        {--limit=0 : Max jobs (debug only)}
        {--once : Process single job and exit (debug only)}
        {--debug}';

    protected $description = 'ASIN listing request worker (W1, supervisor-ready)';

    private bool $shouldStop = false;

    public function handle(): int
    {
        $debug     = (bool) $this->option('debug');
        $idleSleep = max(1, (int) $this->option('sleep'));

        // debug-only controls
        $limit = $debug ? max(0, (int) $this->option('limit')) : 0;
        $once  = $debug ? (bool) $this->option('once') : false;

        $processed = 0;

        // graceful exit
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
        pcntl_signal(SIGINT, fn () => $this->shouldStop = true);

        if ($debug) {
            $this->info('=== ASIN LISTING REQUEST WORKER (W1) ===');
            $this->line('mode=DEBUG');
            $this->line('limit=' . ($limit ?: '∞'));
            $this->line('once=' . ($once ? 'YES' : 'NO'));
            $this->line('sleep=' . $idleSleep);
        }

        while (! $this->shouldStop) {

            // debug limit
            if ($limit > 0 && $processed >= $limit) {
                if ($debug) {
                    $this->warn("debug limit {$limit} reached → exit");
                }
                break;
            }

            $worked = $this->processOne($debug);

            if ($worked) {
                $processed++;

                if ($once) {
                    if ($debug) {
                        $this->warn('debug --once → exit');
                    }
                    break;
                }

                continue;
            }

            // idle
            if ($debug) {
                $this->line('idle…');
            }

            sleep($idleSleep);
        }

        if ($debug) {
            $this->warn('Worker exited gracefully');
        }

        return Command::SUCCESS;
    }

    private function processOne(bool $debug): bool
    {
        $now = Carbon::now();

        DB::beginTransaction();

        try {
            $request = AsinListingSyncRequest::query()
                ->whereIn('status', ['pending', 'fail'])
                ->where('run_after', '<=', $now)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $request) {
                DB::commit();
                return false;
            }

            $sync = DB::table('asins_asin_listing_sync')
                ->where('id', $request->sync_id)
                ->lockForUpdate()
                ->first();

            if (! $sync) {
                throw new \RuntimeException("sync not found (sync_id={$request->sync_id})");
            }

            $asin = DB::table('asins_asins')->where('id', $sync->asin_id)->first();
            if (! $asin) {
                throw new \RuntimeException("asin not found (asin_id={$sync->asin_id})");
            }

            $marketplace = DB::table('marketplaces')->where('id', $sync->marketplace_id)->first();
            if (! $marketplace || ! $marketplace->amazon_id) {
                throw new \RuntimeException('marketplace.amazon_id missing');
            }

            $token = RefreshToken::query()
                ->where('user_id', $sync->user_id)
                ->where('marketplace_id', $sync->marketplace_id)
                ->where('status', 'active')
                ->first();

            if (! $token) {
                throw new \RuntimeException('RefreshToken not found');
            }

            $request->update([
                'status'     => 'processing',
                'attempts'   => $request->attempts + 1,
                'updated_at' => now(),
            ]);

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            if (isset($request)) {
                $request->update(['status' => 'error']);
            }

            $this->error('DB ERROR: ' . $e->getMessage());
            return true;
        }

        // ---------- NODE ----------
        $cmd = [
            'node',
            'spapi/asins/requestCatalogItem.js',
            '--request_id=' . $request->id,
            '--marketplace_id=' . $marketplace->amazon_id,
            '--asin=' . $asin->asin,

            '--lwa_refresh_token=' . $token->lwa_refresh_token,
            '--lwa_client_id=' . $token->lwa_client_id,
            '--lwa_client_secret=' . $token->lwa_client_secret,
            '--aws_access_key_id=' . $token->aws_access_key_id,
            '--aws_secret_access_key=' . $token->aws_secret_access_key,
            '--aws_role_arn=' . $token->aws_role_arn,
            '--sp_api_region=' . $token->sp_api_region,
        ];

        if ($debug) {
            $this->line("NODE request_id={$request->id}");
        }

        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $cmd)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);

        $json = $this->extractLastJson($stdout);

        AsinListingSyncRequestPayload::updateOrCreate(
            ['request_id' => $request->id],
            ['payload' => $json ?? []]
        );

        if (($json['success'] ?? false) === true) {
            DB::transaction(function () use ($request, $sync) {
                $request->update(['status' => 'completed']);

                DB::table('asins_asin_listing_sync')
                    ->where('id', $sync->id)
                    ->where('pipeline', 'request')
                    ->update([
                        'status'   => 'pending',
                        'pipeline' => 'import',
                    ]);
            });
        } else {
            $request->update([
                'status' => 'fail',
                'run_after' => isset($json['retry_after_minutes'])
                    ? now()->addMinutes((int) $json['retry_after_minutes'])
                    : now()->addMinutes(5),
            ]);
        }

        return true;
    }

    private function extractLastJson(string $stdout): ?array
    {
        $lines = preg_split("/\R/", trim($stdout));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '' && $line[0] === '{') {
                $json = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $json;
                }
            }
        }
        return null;
    }
}
