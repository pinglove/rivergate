<?php

namespace App\Console\Commands\Amazon\Orders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Amazon\RefreshToken;

class WorkOrderItems extends Command
{
    protected $signature = 'orders:work-items
        {--sleep=5 : Idle sleep seconds}
        {--limit=0 : Max jobs (debug only)}
        {--once : Process single job and exit (debug only)}
        {--debug}';

    protected $description = 'Order items worker (supervisor-ready)';

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
            $this->info('=== ORDER ITEMS WORKER ===');
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
            $task = DB::table('orders_items_sync')
                ->whereIn('status', ['pending', 'failed'])
                ->where(function ($q) use ($now) {
                    $q->whereNull('run_after')
                      ->orWhere('run_after', '<=', $now);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $task) {
                DB::commit();
                return false;
            }

            $order = DB::table('orders')
                ->where('amazon_order_id', $task->amazon_order_id)
                ->where('marketplace_id', $task->marketplace_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new \RuntimeException("Order not found");
            }

            $marketplace = DB::table('marketplaces')
                ->where('id', $order->marketplace_id)
                ->first();

            if (! $marketplace || ! $marketplace->amazon_id) {
                throw new \RuntimeException("Marketplace.amazon_id missing");
            }

            $token = RefreshToken::query()
                ->where('user_id', $order->user_id)
                ->where('marketplace_id', $order->marketplace_id)
                ->where('status', 'active')
                ->first();

            if (! $token) {

                DB::table('orders_items_sync')
                    ->where('id', $task->id)
                    ->update([
                        'status'      => 'skipped',
                        'last_error'  => 'RefreshToken not found',
                        'updated_at'  => now(),
                    ]);

                if ($debug) {
                    $this->warn("orders_items_sync {$task->id} skipped: RefreshToken not found");
                }

                DB::commit();

                // 🔴 КЛЮЧЕВО
                // false = воркер НЕ будет ретраить эту задачу
                return false;
            }


            DB::table('orders_items_sync')
                ->where('id', $task->id)
                ->update([
                    'status'     => 'processing',
                    'attempts'   => $task->attempts + 1,
                    'updated_at' => now(),
                ]);

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            if (isset($task)) {
                DB::table('orders_items_sync')
                    ->where('id', $task->id)
                    ->update([
                        'status'     => 'failed',
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
            'spapi/orders/importOrderItems.js',
            '--worker_id=' . $task->id,
            '--marketplace_id=' . $marketplace->amazon_id,
            '--seller_id=' . $order->user_id,
            '--order_ids=' . $order->amazon_order_id,

            '--lwa_refresh_token=' . $token->lwa_refresh_token,
            '--lwa_client_id=' . $token->lwa_client_id,
            '--lwa_client_secret=' . $token->lwa_client_secret,
            '--aws_access_key_id=' . $token->aws_access_key_id,
            '--aws_secret_access_key=' . $token->aws_secret_access_key,
            '--aws_role_arn=' . $token->aws_role_arn,
            '--sp_api_region=' . $token->sp_api_region,
        ];

        if ($debug) {
            $this->line("NODE order_items task_id={$task->id}");
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

        if (($json['success'] ?? false) === true) {
            DB::transaction(function () use ($task, $order) {
                DB::table('orders_items_sync')
                    ->where('id', $task->id)
                    ->update([
                        'status'     => 'completed',
                        'updated_at' => now(),
                    ]);

                DB::table('orders')
                    ->where('id', $order->id)
                    ->update([
                        'items_status'      => 'completed',
                        'items_imported_at' => now(),
                        'updated_at'        => now(),
                    ]);
            });
        } else {
            DB::table('orders_items_sync')
                ->where('id', $task->id)
                ->update([
                    'status'     => 'failed',
                    'last_error' => $json['error'] ?? 'unknown error',
                    'run_after'  => isset($json['retry_after_minutes'])
                        ? now()->addMinutes((int) $json['retry_after_minutes'])
                        : null,
                    'updated_at' => now(),
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
