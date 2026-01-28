<?php

namespace App\Console\Commands\Amazon\Orders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DispatchOrderItems extends Command
{
    protected $signature = 'orders:dispatch-items
        {--limit=500}
        {--marketplace_id=}
        {--debug}';

    protected $description = 'Dispatch orders for items import';

    public function handle(): int
    {
        $limit         = (int) $this->option('limit');
        $marketplaceId = $this->option('marketplace_id')
            ? (int) $this->option('marketplace_id')
            : (int) session('active_marketplace');

        $debug = (bool) $this->option('debug');
        $now   = Carbon::now();

        if (!$marketplaceId) {
            $this->error('marketplace_id is required (or active marketplace must be set)');
            return Command::FAILURE;
        }

        if ($debug) {
            $this->info('=== ORDER ITEMS DISPATCHER ===');
            $this->line('limit=' . $limit);
            $this->line('marketplace_id=' . $marketplaceId);
            $this->line('now=' . $now->toDateTimeString());
        }

        /*
         |----------------------------------------------------------------------
         | SELECT candidates
         |----------------------------------------------------------------------
         */

        $orders = DB::table('orders as o')
            ->select(
                'o.id',
                'o.user_id',
                'o.marketplace_id',
                'o.amazon_order_id'
            )
            ->where('o.marketplace_id', $marketplaceId)
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

        if ($debug) {
            $this->line('orders_selected=' . $orders->count());
        }

        if ($orders->isEmpty()) {
            if ($debug) {
                $this->line('No orders to dispatch');
            }
            return Command::SUCCESS;
        }

        /*
         |----------------------------------------------------------------------
         | INSERT into sync table
         |----------------------------------------------------------------------
         */

        $inserted = 0;

        DB::transaction(function () use ($orders, &$inserted, $debug) {
            if ($debug) {
                $this->line('orders_to_insert=' . $orders->count());
            }

            foreach ($orders as $o) {
                DB::table('orders_items_sync')->insert([
                    'user_id'         => $o->user_id,
                    'marketplace_id'  => $o->marketplace_id,
                    'amazon_order_id' => $o->amazon_order_id,

                    'status'     => 'pending',
                    'attempts'   => 0,
                    'run_after'  => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $inserted++;

                if ($debug) {
                    $this->line("Dispatched order {$o->amazon_order_id}");
                }
            }
        });

        if ($debug) {
            $this->info("Dispatched {$inserted} orders");
        }

        return Command::SUCCESS;
    }
}
