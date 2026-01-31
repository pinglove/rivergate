<?php

namespace App\Console\Commands\Amazon\Orders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Amazon\RefreshToken;
use Throwable;

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

            DB::listen(function ($query) {
                $bindings = array_map(
                    fn ($b) => is_null($b)
                        ? 'NULL'
                        : (is_string($b) ? "'" . addslashes($b) . "'" : $b),
                    $query->bindings
                );

                $this->line('');
                $this->line('🧠 SQL:');
                $this->line($query->sql);
                $this->line('📦 Bindings: [' . implode(', ', $bindings) . ']');
                $this->line('⏱ Time: ' . $query->time . ' ms');
            });
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
        DB::beginTransaction();

        try {
            $sync = DB::table('orders_sync')
                ->whereIn('status', ['pending', 'fail'])
                ->where(function ($q) {
                    $q->whereNull('run_after')
                      ->orWhere('run_after', '<=', now());
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
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('DB ERROR: ' . $e->getMessage());
            return false;
        }

        // ---------------- NODE ----------------

        $cmd = [
            'node',
            'spapi/orders/RequestOrders.js',
            '--request_id=' . $sync->id,
            '--marketplace_id=' . $marketplace->amazon_id,
            '--from=' . Carbon::parse($sync->from_date)->toDateString(),
            '--to=' . Carbon::parse($sync->to_date)->toDateString(),
            '--lwa_refresh_token=' . $token->lwa_refresh_token,
            '--lwa_client_id=' . $token->lwa_client_id,
            '--lwa_client_secret=' . $token->lwa_client_secret,
            '--aws_access_key_id=' . $token->aws_access_key_id,
            '--aws_secret_access_key=' . $token->aws_secret_access_key,
            '--aws_role_arn=' . $token->aws_role_arn,
            '--sp_api_region=' . $token->sp_api_region,
        ];

        $cmdString = implode(' ', array_map('escapeshellarg', $cmd));

        if ($debug) {
            $this->line('');
            $this->line('🚀 NODE CMD:');
            $this->line($cmdString);
        }

        $process = proc_open(
            $cmdString,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);

        if ($debug && trim($stderr) !== '') {
            $this->error('NODE STDERR:');
            $this->line($stderr);
        }

        $json = $this->extractLastJson($stdout);

        if (! $json || ($json['success'] ?? false) !== true) {
            $this->retry(
                $sync->id,
                $json['error'] ?? 'Node error',
                (int) ($json['retry_after_minutes'] ?? self::RETRY_DELAY_MINUTES)
            );
            return true;
        }

        // ---------------- UPSERT ----------------

        $rows = [];

        foreach ($json['data']['orders'] as $o) {
            $rows[] = [
                'user_id'                        => $sync->user_id,
                'marketplace_id'                 => $sync->marketplace_id,
                'amazon_order_id'                => $o['amazon_order_id'],

                'merchant_order_id'              => $o['merchant_order_id'],
                'purchase_date'                  => $o['purchase_date'],
                'last_updated_date'              => $o['last_updated_date'],
                'order_status'                   => $o['order_status'],
                'items_status'                   => 'pending',

                'order_type'                     => $o['order_type'],
                'fulfillment_channel'            => $o['fulfillment_channel'],
                'sales_channel'                  => $o['sales_channel'],
                'order_channel'                  => $o['order_channel'],

                'ship_service_level'             => $o['ship_service_level'],
                'shipment_service_level_category'=> $o['shipment_service_level_category'],

                'ship_city'                      => $o['ship_city'],
                'ship_state'                     => $o['ship_state'],
                'ship_postal_code'               => $o['ship_postal_code'],
                'ship_country'                   => $o['ship_country'],

                'payment_method'                 => $o['payment_method'],
                'payment_method_details_json'    => $o['payment_method_details_json'],
                'buyer_info_json'                => $o['buyer_info_json'],
                'buyer_invoice_preference'       => $o['buyer_invoice_preference'],

                'purchase_order_number'          => $o['purchase_order_number'],
                'price_designation'              => $o['price_designation'],

                'order_total_amount'             => $o['order_total_amount'],
                'order_total_currency'           => $o['order_total_currency'],
                'number_of_items_shipped'        => $o['number_of_items_shipped'],
                'number_of_items_unshipped'      => $o['number_of_items_unshipped'],

                'earliest_ship_date'             => $o['earliest_ship_date'],
                'latest_ship_date'               => $o['latest_ship_date'],
                'earliest_delivery_date'         => $o['earliest_delivery_date'],
                'latest_delivery_date'           => $o['latest_delivery_date'],

                'is_business_order'              => (int) $o['is_business_order'],
                'is_prime'                       => (int) $o['is_prime'],
                'is_premium_order'               => (int) $o['is_premium_order'],
                'is_replacement_order'           => (int) $o['is_replacement_order'],
                'is_sold_by_ab'                  => (int) $o['is_sold_by_ab'],
                'is_ispu'                        => (int) $o['is_ispu'],
                'is_global_express_enabled'      => (int) $o['is_global_express_enabled'],
                'is_access_point_order'          => (int) $o['is_access_point_order'],
                'has_regulated_items'            => (int) $o['has_regulated_items'],
                'is_iba'                         => (int) $o['is_iba'],

                'raw_order_json'                 => $o['raw_order_json'],

                'created_at'                     => now(),
                'updated_at'                     => now(),
            ];
        }

        try {
            DB::transaction(function () use ($rows, $sync) {
                if ($rows) {
                    DB::table('orders')->upsert(
                        $rows,
                        ['user_id', 'marketplace_id', 'amazon_order_id'],
                        array_diff(array_keys($rows[0]), [
                            'user_id',
                            'marketplace_id',
                            'amazon_order_id',
                            'created_at',
                        ])
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
        } catch (Throwable $e) {
            $this->error('UPSERT ERROR: ' . $e->getMessage());
            $this->retry($sync->id, $e->getMessage());
        }

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
