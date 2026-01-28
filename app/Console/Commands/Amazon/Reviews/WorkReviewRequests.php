<?php

namespace App\Console\Commands\Amazon\Reviews;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Amazon\RefreshToken;

class WorkReviewRequests extends Command
{
    protected $signature = 'reviews:work
        {--limit=10}
        {--debug}';

    protected $description = 'Amazon Request a Review worker';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $debug = (bool) $this->option('debug');
        $now   = Carbon::now();

        if ($debug) {
            $this->info('=== REVIEW REQUEST WORKER ===');
            $this->line('limit=' . $limit);
            $this->line('now=' . $now->toDateTimeString());
        }

        for ($i = 0; $i < $limit; $i++) {
            DB::beginTransaction();

            try {
                $task = DB::table('review_request_queue')
                    ->whereIn('status', ['pending', 'failed'])
                    ->where(function ($q) use ($now) {
                        $q->whereNull('run_after')
                          ->orWhere('run_after', '<=', $now);
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (!$task) {
                    DB::commit();
                    if ($debug) {
                        $this->line('No tasks found');
                    }
                    return Command::SUCCESS;
                }

                if ($debug) {
                    $this->line("→ processing id={$task->id} order={$task->amazon_order_id} asin={$task->asin}");
                }

                $order = DB::table('orders')
                    ->where('amazon_order_id', $task->amazon_order_id)
                    ->where('marketplace_id', $task->marketplace_id)
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    throw new \RuntimeException(
                        "Order not found (amazon_order_id={$task->amazon_order_id})"
                    );
                }

                $marketplace = DB::table('marketplaces')
                    ->where('id', $task->marketplace_id)
                    ->first();

                if (!$marketplace || !$marketplace->amazon_id) {
                    throw new \RuntimeException(
                        "Marketplace.amazon_id missing (marketplace_id={$task->marketplace_id})"
                    );
                }

                // 🔐 ВАЖНО: ТОЛЬКО через Eloquent (decrypt работает тут)
                $token = RefreshToken::query()
                    ->where('user_id', $task->user_id)
                    ->where('marketplace_id', $task->marketplace_id)
                    ->where('status', 'active')
                    ->first();

                if (!$token) {
                    throw new \RuntimeException(
                        "RefreshToken not found (user_id={$task->user_id}, marketplace_id={$task->marketplace_id})"
                    );
                }

                // mark processing
                DB::table('review_request_queue')
                    ->where('id', $task->id)
                    ->update([
                        'status'     => 'processing',
                        'attempts'   => $task->attempts + 1,
                        'updated_at' => now(),
                    ]);

                DB::commit();

                // ---------------- NODE COMMAND ----------------
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

                if ($debug) {
                    $this->line("NODE CMD:");
                    foreach ($cmd as $c) {
                        $this->line('  ' . $c);
                    }
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

                if ($debug) {
                    $this->line("NODE STDOUT:\n" . $stdout);
                    $this->line("NODE STDERR:\n" . $stderr);
                }

                $json = $this->extractLastJson($stdout);
                if (!$json) {
                    throw new \RuntimeException('Invalid JSON from node');
                }

                // ---------------- RESULT ----------------
                if (($json['success'] ?? false) === true) {
                    DB::table('review_request_queue')
                        ->where('id', $task->id)
                        ->update([
                            'status'        => 'completed',
                            'requested_at'  => now(),
                            'updated_at'    => now(),
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

            } catch (\Throwable $e) {
                DB::rollBack();

                if (isset($task)) {
                    DB::table('review_request_queue')
                        ->where('id', $task->id)
                        ->update([
                            'status'     => 'failed',
                            'last_error' => $e->getMessage(),
                            'updated_at' => now(),
                        ]);
                }

                $this->error(
                    'ERROR task_id=' . ($task->id ?? 'n/a') . ': ' . $e->getMessage()
                );
            }
        }

        return Command::SUCCESS;
    }

    private function extractLastJson(string $stdout): ?array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($stdout));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') continue;
            if ($line[0] === '{') {
                $decoded = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }
        return null;
    }
}
