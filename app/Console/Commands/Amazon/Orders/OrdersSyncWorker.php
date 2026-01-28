<?php

namespace App\Console\Commands\Amazon\Orders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Amazon\RefreshToken;

class OrdersSyncWorker extends Command
{
    protected $signature = 'amazon:orders:worker
        {--once}
        {--debug}';

    protected $description = 'Amazon Orders sync worker (request + import)';

    public function handle(): int
    {
        $once  = (bool) $this->option('once');
        $debug = (bool) $this->option('debug');
        $now   = Carbon::now();

        if ($debug) {
            $this->info('=== AMAZON ORDERS SYNC WORKER ===');
            $this->line('once=' . ($once ? 'yes' : 'no'));
            $this->line('now=' . $now->toDateTimeString());
        }

        do {
            DB::beginTransaction();

            try {
                $sync = DB::table('orders_sync')
                    ->whereIn('status', ['pending', 'fail'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (!$sync) {
                    DB::commit();
                    if ($debug) {
                        $this->line('No orders_sync tasks found');
                    }
                    return Command::SUCCESS;
                }

                $marketplace = DB::table('marketplaces')
                    ->where('id', $sync->marketplace_id)
                    ->first();

                if (!$marketplace || !$marketplace->amazon_id) {
                    throw new \RuntimeException(
                        "marketplace.amazon_id missing (marketplace_id={$sync->marketplace_id})"
                    );
                }

                $token = RefreshToken::query()
                    ->where('user_id', $sync->user_id)
                    ->where('marketplace_id', $sync->marketplace_id)
                    ->where('status', 'active')
                    ->first();

                if (!$token) {
                    throw new \RuntimeException(
                        "RefreshToken not found (user_id={$sync->user_id}, marketplace_id={$sync->marketplace_id})"
                    );
                }

                // mark processing
                DB::table('orders_sync')
                    ->where('id', $sync->id)
                    ->update([
                        'status'     => 'processing',
                        'started_at'=> $now,
                        'updated_at'=> $now,
                    ]);

                DB::commit();

                // ---------- NODE COMMAND ----------
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
                    $masked = array_map(
                        fn ($v) => str_contains($v, '=') ? preg_replace('/=.*/', '=***', $v) : $v,
                        $cmd
                    );
                    $this->line('NODE CMD: ' . implode(' ', $masked));
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

                if (($json['success'] ?? false) !== true) {
                    throw new \RuntimeException(
                        $json['error'] ?? 'Node returned success=false'
                    );
                }

                $orders = $json['data']['orders'] ?? [];
                $imported = 0;

                DB::beginTransaction();

                foreach ($orders as $order) {
                    DB::table('orders')->updateOrInsert(
                        [
                            'marketplace_id'  => $sync->marketplace_id,
                            'amazon_order_id' => $order['amazon_order_id'],
                        ],
                        [
                            'user_id' => $sync->user_id,

                            'merchant_order_id' => $order['merchant_order_id'],
                            'purchase_date' => $order['purchase_date'],
                            'last_updated_date' => $order['last_updated_date'],

                            'order_status' => $order['order_status'],
                            'order_type' => $order['order_type'],

                            'fulfillment_channel' => $order['fulfillment_channel'],
                            'sales_channel' => $order['sales_channel'],
                            'order_channel' => $order['order_channel'],

                            'ship_service_level' => $order['ship_service_level'],
                            'shipment_service_level_category' => $order['shipment_service_level_category'],

                            'ship_city' => $order['ship_city'],
                            'ship_state' => $order['ship_state'],
                            'ship_postal_code' => $order['ship_postal_code'],
                            'ship_country' => $order['ship_country'],

                            'is_business_order' => $order['is_business_order'],
                            'is_prime' => $order['is_prime'],
                            'is_premium_order' => $order['is_premium_order'],
                            'is_replacement_order' => $order['is_replacement_order'],
                            'is_sold_by_ab' => $order['is_sold_by_ab'],
                            'is_ispu' => $order['is_ispu'],
                            'is_global_express_enabled' => $order['is_global_express_enabled'],
                            'is_access_point_order' => $order['is_access_point_order'],
                            'has_regulated_items' => $order['has_regulated_items'],
                            'is_iba' => $order['is_iba'],

                            'purchase_order_number' => $order['purchase_order_number'],
                            'price_designation' => $order['price_designation'],

                            'order_total_amount' => $order['order_total_amount'],
                            'order_total_currency' => $order['order_total_currency'],

                            'number_of_items_shipped' => $order['number_of_items_shipped'],
                            'number_of_items_unshipped' => $order['number_of_items_unshipped'],

                            'earliest_ship_date' => $order['earliest_ship_date'],
                            'latest_ship_date' => $order['latest_ship_date'],
                            'earliest_delivery_date' => $order['earliest_delivery_date'],
                            'latest_delivery_date' => $order['latest_delivery_date'],

                            'payment_method' => $order['payment_method'],
                            'payment_method_details_json' => $order['payment_method_details_json'],
                            'buyer_invoice_preference' => $order['buyer_invoice_preference'],
                            'buyer_info_json' => $order['buyer_info_json'],

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

                DB::commit();

                if ($debug) {
                    $this->info(
                        "orders_sync ID={$sync->id} completed, imported={$imported}"
                    );
                }

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

                $this->error(
                    'ERROR orders_sync_id=' . ($sync->id ?? 'n/a') . ': ' . $e->getMessage()
                );
            }

        } while (!$once);

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
