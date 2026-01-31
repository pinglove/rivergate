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
        {--limit=100 : Max jobs (debug only)}
        {--once : Process single job and exit (debug only)}
        {--debug}';

    protected $description = 'Amazon Orders sync worker (production safe)';

    private const MAX_ATTEMPTS = 5;
    private const RETRY_DELAY_MINUTES = 5;

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
            $this->info('=== AMAZON ORDERS SYNC WORKER (DEBUG MODE) ===');
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
            $sync = DB::table('orders_sync')
                ->whereIn('status', ['pending', 'fail'])
                ->where(function ($q) use ($now) {
                    $q->whereNull('run_after')
                      ->orWhere('run_after', '<=', $now);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $sync) {
                DB::commit();
                return false;
            }

            if ($sync->attempts >= self::MAX_ATTEMPTS) {
                DB::table('orders_sync')
                    ->where('id', $sync->id)
                    ->update([
                        'status'        => 'skipped',
                        'error_message' => 'Max attempts reached',
                        'finished_at'   => now(),
                        'updated_at'    => now(),
                    ]);

                DB::commit();
                return false;
            }

            $marketplace = DB::table('marketplaces')
                ->where('id', $sync->marketplace_id)
                ->first();

            if (! $marketplace || ! $marketplace->amazon_id) {
                DB::table('orders_sync')
                    ->where('id', $sync->id)
                    ->update([
                        'status'        => 'skipped',
                        'error_message' => 'Marketplace misconfigured',
                        'finished_at'   => now(),
                        'updated_at'    => now(),
                    ]);

                DB::commit();
                return false;
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

                DB::commit();
                return false;
            }

            DB::table('orders_sync')
                ->where('id', $sync->id)
                ->update([
                    'status'      => 'processing',
                    'attempts'    => $sync->attempts + 1,
                    'started_at'  => now(),
                    'updated_at'  => now(),
                ]);

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            return false;
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

        // 🔎 DEBUG: реальная shell-команда
        $cmdString = implode(' ', array_map('escapeshellarg', $cmd));

        if ($debug) {
            $this->line('');
            $this->line("NODE CMD (orders_sync_id={$sync->id}):");
            $this->line($cmdString);
            $this->line('');
        }

        $process = proc_open(
            $cmdString,
            [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ],
            $pipes,
            base_path()
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);

        if ($debug && trim($stderr) !== '') {
            $this->error("NODE STDERR:");
            $this->line($stderr);
        }

        $json = $this->extractLastJson($stdout);

        if (! $json) {
            $this->retry($sync->id, 'Node returned no JSON');
            return true;
        }

        if (($json['success'] ?? false) !== true) {
            $this->retry(
                $sync->id,
                $json['error'] ?? 'Node error',
                (int) ($json['retry_after_minutes'] ?? self::RETRY_DELAY_MINUTES)
            );
            return true;
        }
        /////////////////////////////////////////////////////////
        // ✅ SUCCESS
        $orders = $json['data']['orders'] ?? [];

        $rows = [];

        foreach ($orders as $order) {
            $rows[] = [
                // 🔑 keys
                'user_id'         => $sync->user_id,
                'marketplace_id'  => $sync->marketplace_id,
                'amazon_order_id' => $order['amazon_order_id'],

                // 🧾 basic data
                'merchant_order_id' => $order['merchant_order_id'] ?? null,
                'purchase_date'     => $order['purchase_date'] ?? null,
                'last_updated_date' => $order['last_updated_date'] ?? null,

                'order_status' => $order['order_status'] ?? 'Unknown',
                'items_status' => 'pending',

                // 📦 meta
                'order_type'          => $order['order_type'] ?? null,
                'fulfillment_channel' => $order['fulfillment_channel'] ?? null,
                'sales_channel'       => $order['sales_channel'] ?? null,
                'payment_method'      => $order['payment_method'] ?? null,

                // 🧠 json
                'payment_method_details_json' => $order['payment_method_details_json'] ?? null,
                'buyer_info_json'             => $order['buyer_info_json'] ?? null,
                'raw_order_json'              => $order['raw_order_json'] ?? null,

                // 🚨 NOT NULL flags (КРИТИЧНО)
                'is_business_order' => (int) ($order['is_business_order'] ?? 0),
                'is_iba'            => (int) ($order['is_iba'] ?? 0),

                // ⏱ timestamps
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($rows, $sync) {

            if (! empty($rows)) {
                DB::table('orders')->upsert(
                    $rows,
                    ['user_id', 'marketplace_id', 'amazon_order_id'],
                    [
                        'order_status',
                        'last_updated_date',
                        'raw_order_json',
                        'updated_at',
                    ]
                );
            }

            DB::table('orders_sync')
                ->where('id', $sync->id)
                ->update([
                    'status'         => 'completed',
                    'orders_fetched' => count($rows),
                    'imported_count' => count($rows),
                    'finished_at'    => now(),
                    'updated_at'     => now(),
                ]);
        });

        /////////////////////////////////////////////////////////////

        return true;
    }

    private function retry(int $id, string $error, int $delayMinutes = self::RETRY_DELAY_MINUTES): void
    {
        DB::table('orders_sync')
            ->where('id', $id)
            ->update([
                'status'        => 'fail',
                'run_after'     => now()->addMinutes(max(1, $delayMinutes)),
                'error_message' => $error,
                'updated_at'    => now(),
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
