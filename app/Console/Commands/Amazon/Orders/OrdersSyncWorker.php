<?php

namespace App\Console\Commands\Amazon\Orders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Amazon\RefreshToken;

class OrdersSyncWorker extends Command
{
    protected $signature = 'amazon:orders:worker
        {--sleep=5 : Idle sleep seconds}
        {--limit=0 : Max jobs (debug only)}
        {--once : Process single job and exit (debug only)}
        {--debug}';

    protected $description = 'Amazon Orders sync worker (supervisor-ready)';

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
            $this->info('=== AMAZON ORDERS SYNC WORKER ===');
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
            $sync = DB::table('orders_sync')
                ->whereIn('status', ['pending', 'fail'])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $sync) {
                DB::commit();
                return false;
            }

            $marketplace = DB::table('marketplaces')
                ->where('id', $sync->marketplace_id)
                ->first();

            if (! $marketplace || ! $marketplace->amazon_id) {
                throw new \RuntimeException("marketplace.amazon_id missing");
            }

            $token = RefreshToken::query()
                ->where('user_id', $sync->user_id)
                ->where('marketplace_id', $sync->marketplace_id)
                ->where('status', 'active')
                ->first();

            if (! $token) {

                DB::table('orders_sync')
                    ->where('id', $sync->id)
                    ->update([
                        'status'        => 'skipped',
                        'error_message' => 'RefreshToken not found',
                        'finished_at'   => now(),
                        'updated_at'    => now(),
                    ]);

                if ($debug) {
                    $this->warn("orders_sync {$sync->id} skipped: RefreshToken not found");
                }

                DB::commit();

                // 🔴 КЛЮЧЕВОЕ:
                // false = "ничего не обработали"
                // воркер НЕ будет ретраить
                return false;
            }


            DB::table('orders_sync')
                ->where('id', $sync->id)
                ->update([
                    'status'     => 'processing',
                    'started_at'=> $now,
                    'updated_at'=> $now,
                ]);

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            if (isset($sync)) {
                DB::table('orders_sync')
                    ->where('id', $sync->id)
                    ->update([
                        'status' => 'fail',
                        'error_message' => $e->getMessage(),
                        'updated_at' => now(),
                    ]);
            }

            $this->error('DB ERROR: ' . $e->getMessage());
            return true;
        }

        // ---------- NODE ----------
        $cmd = [
            'node',
            'spapi/orders/RequestOrders.js',
            '--request_id=' . $sync->id,
            '--marketplace_id=' . $marketplace->amazon_id,
            '--from=' . Carbon::parse($sync->from_date)->toDateString(),
            '--to='   . Carbon::parse($sync->to_date)->toDateString(),

            '--lwa_refresh_token=' . $token->lwa_refresh_token,
            '--lwa_client_id=' . $token->lwa_client_id,
            '--lwa_client_secret=' . $token->lwa_client_secret,
            '--aws_access_key_id=' . $token->aws_access_key_id,
            '--aws_secret_access_key=' . $token->aws_secret_access_key,
            '--aws_role_arn=' . $token->aws_role_arn,
            '--sp_api_region=' . $token->sp_api_region,
        ];

        if ($debug) {
            $this->line("NODE request orders_sync_id={$sync->id}");
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

        if (! $json || ($json['success'] ?? false) !== true) {
            DB::table('orders_sync')
                ->where('id', $sync->id)
                ->update([
                    'status' => 'fail',
                    'error_message' => $json['error'] ?? 'Node error',
                    'updated_at' => now(),
                ]);

            return true;
        }

        $orders = $json['data']['orders'] ?? [];
        $imported = 0;

        DB::transaction(function () use ($orders, $sync, &$imported) {
            foreach ($orders as $order) {
                DB::table('orders')->updateOrInsert(
                    [
                        'marketplace_id'  => $sync->marketplace_id,
                        'amazon_order_id' => $order['amazon_order_id'],
                    ],
                    [
                        'user_id' => $sync->user_id,
                        'order_status' => $order['order_status'] ?? 'Unknown',
                        'raw_order_json' => $order['raw_order_json'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $imported++;
            }

            DB::table('orders_sync')
                ->where('id', $sync->id)
                ->update([
                    'status'          => 'completed',
                    'orders_fetched'  => count($orders),
                    'imported_count'  => $imported,
                    'finished_at'     => now(),
                    'updated_at'      => now(),
                ]);
        });

        if ($debug) {
            $this->info("orders_sync {$sync->id} completed, imported={$imported}");
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
