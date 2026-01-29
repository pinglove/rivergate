<?php

namespace App\Console\Commands\Amazon\Asins;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Amazon\Asins\AsinUserMpSyncUnresolved;
use App\Models\Amazon\RefreshToken;

class WorkUnresolvedAsins extends Command
{
    protected $signature = 'asins:work-unresolved
        {--sleep=5 : Idle sleep seconds}
        {--limit=0 : Max jobs (debug only)}
        {--once : Process single job and exit (debug only)}
        {--debug}';

    protected $description = 'Resolve unresolved ASINs (supervisor-ready)';

    private const MAX_ATTEMPTS = 5;

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
            $this->info('=== UNRESOLVED ASIN RESOLVER ===');
            $this->line('mode=DEBUG');
            $this->line('limit=' . ($limit ?: '∞'));
            $this->line('once=' . ($once ? 'YES' : 'NO'));
            $this->line('sleep=' . $idleSleep);
        }

        while (! $this->shouldStop) {

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
            /** @var AsinUserMpSyncUnresolved|null $row */
            $row = AsinUserMpSyncUnresolved::query()
                ->where('status', 'pending')
                ->where(function ($q) use ($now) {
                    $q->whereNull('run_after')
                      ->orWhere('run_after', '<=', $now);
                })
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::commit();
                return false;
            }

            $marketplace = DB::table('marketplaces')
                ->where('id', $row->marketplace_id)
                ->first();

            if (! $marketplace || ! $marketplace->amazon_id) {
                throw new \RuntimeException('marketplace.amazon_id missing');
            }

            $token = RefreshToken::query()
                ->where('user_id', $row->user_id)
                ->where('marketplace_id', $row->marketplace_id)
                ->where('status', 'active')
                ->first();

            if (! $token) {
                throw new \RuntimeException('RefreshToken not found');
            }

            $productIdType = $this->mapProductIdType($row->product_id_type);
            if (! $productIdType || ! $row->product_id) {
                $row->update([
                    'status' => 'failed',
                    'last_error' => 'Unsupported product_id_type / product_id',
                    'updated_at' => now(),
                ]);
                DB::commit();
                return true;
            }

            $row->update([
                'status'     => 'processing',
                'attempts'   => $row->attempts + 1,
                'updated_at' => now(),
            ]);

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            if (isset($row)) {
                $row->update([
                    'status'     => $row->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'run_after'  => now()->addMinutes(5),
                    'last_error' => $e->getMessage(),
                    'updated_at' => now(),
                ]);
            }

            $this->error('DB ERROR: ' . $e->getMessage());
            return true;
        }

        // ---------- NODE ----------
        $cmd = [
            'node',
            'spapi/asins/resolveByIdentifier.js',
            '--request_id=' . $row->id,
            '--marketplace_id=' . $marketplace->amazon_id,
            '--product_id_type=' . $productIdType,
            '--product_id=' . $row->product_id,

            '--lwa_refresh_token=' . $token->lwa_refresh_token,
            '--lwa_client_id=' . $token->lwa_client_id,
            '--lwa_client_secret=' . $token->lwa_client_secret,
            '--aws_access_key_id=' . $token->aws_access_key_id,
            '--aws_secret_access_key=' . $token->aws_secret_access_key,
            '--aws_role_arn=' . $token->aws_role_arn,
            '--sp_api_region=' . ($token->sp_api_region ?? 'eu'),
        ];

        if ($debug) {
            $this->line("NODE unresolved_id={$row->id}");
        }

        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $cmd)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        $stdout = stream_get_contents($pipes[1]);
        proc_close($process);

        $json = $this->extractLastJson($stdout);

        if (($json['success'] ?? false) === true) {
            match ($json['status'] ?? null) {
                'resolved'   => $this->handleResolved($row, $json),
                'ambiguous'  => $row->update(['status' => 'ambiguous', 'updated_at' => now()]),
                'not_found'  => $row->update(['status' => 'not_found', 'updated_at' => now()]),
                default      => $this->handleFail($row, $json),
            };
        } else {
            $this->handleFail($row, $json);
        }

        return true;
    }

    // helpers (без изменений по смыслу)

    private function mapProductIdType(?string $type): ?string
    {
        return match ((string) $type) {
            '4', 'EAN' => 'EAN',
            '3', 'UPC' => 'UPC',
            'GTIN'     => 'GTIN',
            default    => null,
        };
    }

    private function handleResolved(AsinUserMpSyncUnresolved $row, array $json): void
    {
        $asin = $json['resolved_asin'] ?? null;

        if (! $asin) {
            $this->handleFail($row, ['error' => 'resolved without asin']);
            return;
        }

        $asinId = DB::table('asins_asins')
            ->where('user_id', $row->user_id)
            ->where('marketplace_id', $row->marketplace_id)
            ->where('asin', $asin)
            ->value('id');

        if (! $asinId) {
            $asinId = DB::table('asins_asins')->insertGetId([
                'user_id'        => $row->user_id,
                'marketplace_id'=> $row->marketplace_id,
                'asin'           => $asin,
                'title'          => $row->title,
                'status'         => 'active',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $row->update([
            'status'     => 'resolved',
            'asin_id'    => $asinId,
            'run_after'  => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function handleFail(AsinUserMpSyncUnresolved $row, array $json): void
    {
        $retryAfter = (int) ($json['retry_after_minutes'] ?? 5);

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            $row->update([
                'status'     => 'failed',
                'run_after'  => null,
                'last_error' => $json['error'] ?? 'Max attempts reached',
                'updated_at' => now(),
            ]);
            return;
        }

        $row->update([
            'status'     => 'pending',
            'run_after'  => now()->addMinutes($retryAfter),
            'last_error' => $json['error'] ?? 'Retry scheduled',
            'updated_at' => now(),
        ]);
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
