<?php

namespace App\Console\Commands\Amazon\Orders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Amazon\RefreshToken;

class WorkOrderItems extends Command
{
    protected $signature = 'orders:work-items
        {--sleep=5 : Idle sleep seconds}
        {--limit=0 : Max jobs (debug only)}
        {--once : Process single job and exit (debug only)}
        {--debug}';

    protected $description = 'Order items worker (production-safe)';

    private const MAX_ATTEMPTS = 5;
    private const DEFAULT_RETRY_MINUTES = 5;

    private bool $shouldStop = false;

    public function handle(): int
    {
        $debug     = (bool) $this->option('debug');
        $idleSleep = max(1, (int) $this->option('sleep'));
        $limit     = $debug ? max(0, (int) $this->option('limit')) : 0;
        $once      = $debug ? (bool) $this->option('once') : false;

        $processed = 0;

        // graceful exit
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
        pcntl_signal(SIGINT, fn () => $this->shouldStop = true);

        if ($debug) {
            $this->info('=== ORDER ITEMS WORKER (PROD SAFE) ===');
            $this->line('limit=' . ($limit ?: '∞'));
            $this->line('once=' . ($once ? 'YES' : 'NO'));
            $this->line('sleep=' . $idleSleep);
        }

        while (! $this->shouldStop) {

            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $worked = $this->processOne($debug);

            if ($worked) {
                $processed++;

                if ($once) {
                    break;
                }

                continue;
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
        $now = now();

        DB::beginTransaction();

        try {
            $task = DB::table('orders_items_sync')
                ->where(function ($q) use ($now) {
                    // ✅ pending берём всегда
                    $q->where('status', 'pending')

                      // ✅ failed берём только если пришло время ретрая и attempts < MAX
                      ->orWhere(function ($q2) use ($now) {
                          $q2->where('status', 'failed')
                             ->where('attempts', '<', self::MAX_ATTEMPTS)
                             ->where(function ($q3) use ($now) {
                                 $q3->whereNull('run_after')
                                    ->orWhere('run_after', '<=', $now);
                             });
                      });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $task) {
                DB::commit();
                return false;
            }

            // ⛔ HARD STOP: max attempts (на всякий случай)
            if ((int) $task->attempts >= self::MAX_ATTEMPTS) {
                DB::table('orders_items_sync')
                    ->where('id', $task->id)
                    ->update([
                        'status'      => 'skipped',
                        'last_error'  => 'Max attempts reached',
                        'finished_at' => now(),
                        'updated_at'  => now(),
                    ]);

                DB::commit();
                return false;
            }

            // Важно: order для items обязан существовать
            $order = DB::table('orders')
                ->where('amazon_order_id', $task->amazon_order_id)
                ->where('marketplace_id', $task->marketplace_id)
                ->first();

            // ✅ Это НЕ terminal: возможно order ещё не импортирован → retry
            if (! $order) {
                DB::table('orders_items_sync')
                    ->where('id', $task->id)
                    ->update([
                        'status'     => 'failed',
                        'last_error' => 'Order not imported yet',
                        'run_after'  => now()->addMinutes(self::DEFAULT_RETRY_MINUTES),
                        'updated_at' => now(),
                    ]);

                DB::commit();
                return true;
            }

            $marketplace = DB::table('marketplaces')
                ->where('id', $order->marketplace_id)
                ->first();

            // ❌ terminal: конфиг сломан
            if (! $marketplace || ! $marketplace->amazon_id) {
                DB::table('orders_items_sync')
                    ->where('id', $task->id)
                    ->update([
                        'status'      => 'skipped',
                        'last_error'  => 'Marketplace misconfigured',
                        'finished_at' => now(),
                        'updated_at'  => now(),
                    ]);

                DB::commit();
                return false;
            }

            $token = RefreshToken::query()
                ->where('user_id', $order->user_id)
                ->where('marketplace_id', $order->marketplace_id)
                ->where('status', 'active')
                ->first();

            // ❌ terminal: нет токена → бессмысленно ретраить
            if (! $token) {
                DB::table('orders_items_sync')
                    ->where('id', $task->id)
                    ->update([
                        'status'      => 'skipped',
                        'last_error'  => 'RefreshToken not found',
                        'finished_at' => now(),
                        'updated_at'  => now(),
                    ]);

                DB::commit();
                return false;
            }

            // 🔒 CLAIM TASK
            DB::table('orders_items_sync')
                ->where('id', $task->id)
                ->update([
                    'status'     => 'processing',
                    'attempts'   => (int) $task->attempts + 1,
                    'started_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            if ($debug) {
                $this->error('DB ERROR: ' . $e->getMessage());
            }
            return false;
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

        // ❌ Node did not return JSON → retry with backoff
        if (! $json) {
            $this->retry($task->id, 'Node returned no JSON', self::DEFAULT_RETRY_MINUTES);
            return true;
        }

        if (($json['success'] ?? false) !== true) {
            $this->retry(
                $task->id,
                $json['error'] ?? 'Node error',
                (int) ($json['retry_after_minutes'] ?? self::DEFAULT_RETRY_MINUTES)
            );
            return true;
        }

        // ✅ SUCCESS
        DB::transaction(function () use ($task, $order) {
            DB::table('orders_items_sync')
                ->where('id', $task->id)
                ->update([
                    'status'      => 'completed',
                    'finished_at' => now(),
                    'updated_at'  => now(),
                ]);

            DB::table('orders')
                ->where('id', $order->id)
                ->update([
                    'items_status'      => 'completed',
                    'items_imported_at' => now(),
                    'updated_at'        => now(),
                ]);
        });

        return true;
    }

    private function retry(int $taskId, string $error, int $delayMinutes): void
    {
        // Если уже дошли до MAX_ATTEMPTS — terminal
        $row = DB::table('orders_items_sync')->where('id', $taskId)->first();
        if ($row && (int) $row->attempts >= self::MAX_ATTEMPTS) {
            DB::table('orders_items_sync')
                ->where('id', $taskId)
                ->update([
                    'status'      => 'skipped',
                    'last_error'  => 'Max attempts reached: ' . $error,
                    'finished_at' => now(),
                    'updated_at'  => now(),
                ]);
            return;
        }

        DB::table('orders_items_sync')
            ->where('id', $taskId)
            ->update([
                'status'     => 'failed',
                'last_error' => $error,
                'run_after'  => now()->addMinutes(max(1, $delayMinutes)),
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
