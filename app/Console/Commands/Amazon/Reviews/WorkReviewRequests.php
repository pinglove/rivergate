<?php

namespace App\Console\Commands\Amazon\Reviews;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\Amazon\RefreshToken;

class WorkReviewRequests extends Command
{
    protected $signature = 'reviews:work
        {--once : Process one task and exit}
        {--limit=10 : Max tasks per loop}
        {--sleep=5 : Sleep seconds when no tasks}
        {--debug : Verbose console output}';

    protected $description = 'Amazon Request a Review worker (Supervisor-ready)';

    private const MAX_ATTEMPTS = 5;
    private const STUCK_MINUTES = 10;

    public function handle(): int
    {
        $once  = (bool) $this->option('once');
        $limit = max(1, (int) $this->option('limit'));
        $sleep = max(1, (int) $this->option('sleep'));
        $debug = (bool) $this->option('debug');

        $this->setupLogger();

        if ($debug) {
            $this->info('=== REVIEW REQUEST WORKER STARTED ===');
            $this->line('once=' . ($once ? 'yes' : 'no'));
            $this->line('limit=' . $limit);
            $this->line('sleep=' . $sleep);
        }

        while (true) {
            $processed = 0;

            for ($i = 0; $i < $limit; $i++) {
                $task = null;

                DB::beginTransaction();

                try {
                    $now = Carbon::now();

                    $task = DB::table('review_request_queue')
                        ->where('attempts', '<', self::MAX_ATTEMPTS)
                        ->where(function ($q) use ($now) {
                            $q->whereIn('status', ['pending', 'failed'])
                              ->orWhere(function ($q) use ($now) {
                                  $q->where('status', 'processing')
                                    ->where('updated_at', '<', $now->copy()->subMinutes(self::STUCK_MINUTES));
                              });
                        })
                        ->where(function ($q) use ($now) {
                            $q->whereNull('run_after')
                              ->orWhere('run_after', '<=', $now);
                        })
                        ->orderBy('run_after')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();

                    if (!$task) {
                        DB::commit();
                        break;
                    }

                    DB::table('review_request_queue')
                        ->where('id', $task->id)
                        ->update([
                            'status'     => 'processing',
                            'attempts'   => $task->attempts + 1,
                            'updated_at' => now(),
                        ]);

                    DB::commit();

                    $processed++;

                    $this->processTask($task, $debug);

                } catch (\Throwable $e) {
                    DB::rollBack();

                    if ($task) {
                        DB::table('review_request_queue')
                            ->where('id', $task->id)
                            ->update([
                                'status'     => 'failed',
                                'last_error' => $e->getMessage(),
                                'updated_at' => now(),
                            ]);
                    }

                    Log::error('[REVIEW WORKER] exception', [
                        'task_id' => $task->id ?? null,
                        'error'   => $e->getMessage(),
                    ]);

                    if ($debug) {
                        $this->error('ERROR: ' . $e->getMessage());
                    }
                }

                if ($once) {
                    return Command::SUCCESS;
                }
            }

            if ($processed === 0) {
                if ($once) {
                    return Command::SUCCESS;
                }

                if ($debug) {
                    $this->line("No tasks, sleeping {$sleep}s");
                }

                sleep($sleep);
            }
        }
    }

    private function processTask(object $task, bool $debug): void
    {
        if ($debug) {
            $this->line("→ processing id={$task->id} order={$task->amazon_order_id} asin={$task->asin}");
        }

        $order = DB::table('orders')
            ->where('amazon_order_id', $task->amazon_order_id)
            ->where('marketplace_id', $task->marketplace_id)
            ->first();

        if (!$order) {
            throw new \RuntimeException('Order not found');
        }

        $marketplace = DB::table('marketplaces')
            ->where('id', $task->marketplace_id)
            ->first();

        if (!$marketplace || !$marketplace->amazon_id) {
            throw new \RuntimeException('Marketplace.amazon_id missing');
        }

        $token = RefreshToken::query()
            ->where('user_id', $task->user_id)
            ->where('marketplace_id', $task->marketplace_id)
            ->where('status', 'active')
            ->first();

        if (!$token) {
            throw new \RuntimeException('Active RefreshToken not found');
        }

        $cmd = [
            'node',
            base_path('spapi/reviews/requestReview.js'),
            '--request_id=' . $task->id,
            '--marketplace_id=' . $marketplace->amazon_id,
            '--amazon_order_id=' . $task->amazon_order_id,
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

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start node process');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);

        if ($debug) {
            $this->line("NODE STDOUT:\n{$stdout}");
            $this->line("NODE STDERR:\n{$stderr}");
        }

        $json = $this->extractLastJson($stdout);
        if (!$json) {
            throw new \RuntimeException('Invalid JSON from node');
        }

        if (($json['success'] ?? false) === true) {
            DB::table('review_request_queue')
                ->where('id', $task->id)
                ->update([
                    'status'       => 'completed',
                    'requested_at' => now(),
                    'updated_at'   => now(),
                ]);
        } else {
            DB::table('review_request_queue')
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
    }

    private function extractLastJson(string $stdout): ?array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($stdout));
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

    private function setupLogger(): void
    {
        Log::build([
            'driver' => 'single',
            'path'   => storage_path('logs/review-worker.log'),
            'level'  => 'info',
            'days'   => 1, // хранить ≤ 1 суток
        ]);
    }
}
