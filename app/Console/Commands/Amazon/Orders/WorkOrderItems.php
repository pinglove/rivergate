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
    private const DEFAULT_RETRY_MINUTES = 10;

    private bool $shouldStop = false;

    public function handle(): int
    {
        $debug     = (bool) $this->option('debug');
        $idleSleep = max(1, (int) $this->option('sleep'));
        $limit     = $debug ? max(0, (int) $this->option('limit')) : 0;
        $once      = $debug ? (bool) $this->option('once') : false;

        $processed = 0;

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

        return Command::SUCCESS;
    }

    private function processOne(bool $debug): bool
    {
        $now = now();

        DB::beginTransaction();

        try {
            $task = DB::table('orders_items_sync')
                ->where(function ($q) use ($now) {
                    $q->where('status', 'pending')
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

            $order = DB::table('orders')
                ->where('amazon_order_id', $task->amazon_order_id)
                ->where('marketplace_id', $task->marketplace_id)
                ->first();

            // retryable: orders may arrive later
            if (! $order) {
                $this->markFailed($task->id, (int) $task->attempts, 'Order not imported yet', self::DEFAULT_RETRY_MINUTES);
                DB::commit();
                return true;
            }

            $marketplace = DB::table('marketplaces')
                ->where('id', $order->marketplace_id)
                ->first();

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

            // CLAIM
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

        if ($debug) {
            $this->line("NODE order_items task_id={$task->id}");
        }

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

        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $cmd)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        proc_close($process);

        $json = $this->extractLastJson($stdout);

        if (! $json) {
            $msg = $this->buildNodeError('Node returned no JSON', $stdout, $stderr);
            $this->markFailed($task->id, (int) $task->attempts + 1, $msg, self::DEFAULT_RETRY_MINUTES);
            return true;
        }

        if (($json['success'] ?? false) !== true) {
            $delay = (int) ($json['retry_after_minutes'] ?? self::DEFAULT_RETRY_MINUTES);
            $msg = $this->buildNodeError($json['error'] ?? 'Node error', $stdout, $stderr);
            $this->markFailed($task->id, (int) $task->attempts + 1, $msg, $delay);
            return true;
        }

        DB::transaction(function () use ($task, $order) {
            DB::table('orders_items_sync')
                ->where('id', $task->id)
                ->update([
                    'status'      => 'completed',
                    'finished_at' => now(),
                    'updated_at'  => now(),
                    'run_after'   => null,
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

    private function markFailed(int $taskId, int $attempts, string $error, int $delayMinutes): void
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            DB::table('orders_items_sync')
                ->where('id', $taskId)
                ->update([
                    'status'      => 'skipped',
                    'last_error'  => $this->truncate($error, 3000),
                    'finished_at' => now(),
                    'updated_at'  => now(),
                ]);
            return;
        }

        DB::table('orders_items_sync')
            ->where('id', $taskId)
            ->update([
                'status'     => 'failed',
                'last_error' => $this->truncate($error, 3000),
                'run_after'  => now()->addMinutes(max(1, $delayMinutes)),
                'updated_at' => now(),
            ]);
    }

    private function buildNodeError(string $headline, string $stdout, string $stderr): string
    {
        $out = trim($stdout);
        $err = trim($stderr);

        // часто stderr пустой, а ошибка в stdout
        $tailStdout = $this->tail($out, 1200);
        $tailStderr = $this->tail($err, 1200);

        $msg = $headline;

        if ($tailStderr !== '') {
            $msg .= " | stderr_tail=" . $tailStderr;
        }

        if ($tailStdout !== '') {
            $msg .= " | stdout_tail=" . $tailStdout;
        }

        return $msg;
    }

    private function tail(string $s, int $len): string
    {
        $s = trim($s);
        if ($s === '') return '';
        return mb_strlen($s) > $len ? mb_substr($s, -$len) : $s;
    }

    private function truncate(string $s, int $len): string
    {
        $s = trim($s);
        if ($s === '') return $s;
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) : $s;
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
