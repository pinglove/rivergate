<?php

namespace App\Console\Commands\Amazon\Orders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DispatchOrderItems extends Command
{
    protected $signature = 'orders:dispatch-items
        {--limit=500 : Max orders per marketplace}
        {--marketplace_id= : Optional marketplace filter}
        {--debug}';

    protected $description = 'Dispatch orders for items import';

    public function handle(): int
    {
        $limit         = (int) $this->option('limit');
        $marketplaceId = $this->option('marketplace_id');
        $debug         = (bool) $this->option('debug');

        $now = Carbon::now();

        if ($debug) {
            $this->info('=== ORDER ITEMS DISPATCHER ===');
            $this->line('limit=' . $limit);
            $this->line('marketplace_id=' . ($marketplaceId ?: 'ALL'));
            $this->line('now=' . $now->toDateTimeString());
        }

        /*
         |------------------------------------------------------------
         | Determine marketplaces
         |------------------------------------------------------------
         */

        if ($marketplaceId) {
            $marketplaces = collect([(int) $marketplaceId]);
        } else {
            $marketplaces = DB::table('marketplaces')
                ->whereNotNull('amazon_id')
                ->pluck('id');
        }

        if ($marketplaces->isEmpty()) {
            if ($debug) {
                $this->warn('No marketplaces found');
            }
            return Command::SUCCESS;
        }

        $totalInserted = 0;

        foreach ($marketplaces as $mpId) {

            if ($debug) {
                $this->line("Processing marketplace_id={$mpId}");
            }

            /*
             |------------------------------------------------------------
             | Select candidate orders
             |------------------------------------------------------------
             */

            $orders = DB::table('orders as o')
                ->select(
                    'o.user_id',
                    'o.marketplace_id',
                    'o.amazon_order_id'
                )
                ->where('o.marketplace_id', $mpId)
                ->where('o.order_status', 'Shipped')
                ->where('o.items_status', 'pending')
                ->whereNull('o.items_imported_at')
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('orders_items_sync as s')
                        ->whereColumn('s.amazon_order_id', 'o.amazon_order_id')
                        ->whereColumn('s.marketplace_id', 'o.marketplace_id');
                })
                ->orderBy('o.purchase_date')
                ->limit($limit)
                ->get();

            if ($orders->isEmpty()) {
                continue;
            }

            /*
             |------------------------------------------------------------
             | Insert with protection
             |------------------------------------------------------------
             */

            DB::transaction(function () use ($orders, &$totalInserted) {
                foreach ($orders as $o) {
                    DB::table('orders_items_sync')->insertOrIgnore([
                        'user_id'         => $o->user_id,
                        'marketplace_id'  => $o->marketplace_id,
                        'amazon_order_id' => $o->amazon_order_id,
                        'status'          => 'pending',
                        'attempts'        => 0,
                        'run_after'       => null,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);

                    $totalInserted++;
                }
            });
        }

        if ($debug) {
            $this->info("Dispatched total: {$totalInserted}");
        }

        return Command::SUCCESS;
    }
}
