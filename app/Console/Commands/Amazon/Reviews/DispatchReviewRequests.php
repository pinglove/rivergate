<?php

namespace App\Console\Commands\Amazon\Reviews;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DispatchReviewRequests extends Command
{
    protected $signature = 'reviews:dispatch
        {--limit=500}
        {--marketplace_id=}
        {--debug}';

    protected $description = 'Dispatch Amazon Request a Review jobs (ASIN-first)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $debug = (bool) $this->option('debug');

        $marketplaceId = $this->option('marketplace_id')
            ? (int) $this->option('marketplace_id')
            : (int) session('active_marketplace');

        if (!$marketplaceId) {
            $this->error('marketplace_id is required (or active marketplace must be set)');
            return Command::FAILURE;
        }

        $now = Carbon::now();

        if ($debug) {
            $this->info('=== REVIEW REQUEST DISPATCHER (ASIN-FIRST) ===');
            $this->line('limit=' . $limit);
            $this->line('marketplace_id=' . $marketplaceId);
            $this->line('now=' . $now->toDateTimeString());
        }

        /*
         |--------------------------------------------------------------------------
         | 1️⃣ Находим eligible (order + asin)
         |--------------------------------------------------------------------------
         */

        $rows = DB::table('orders_items as oi')
            ->join('asins_asins as a', function ($j) {
                $j->on('a.asin', '=', 'oi.asin')
                  ->on('a.marketplace_id', '=', 'oi.marketplace_id');
            })
            ->join('review_request_settings as rrs', function ($j) {
                $j->on('rrs.asin_id', '=', 'a.id')
                  ->on('rrs.marketplace_id', '=', 'oi.marketplace_id')
                  ->where('rrs.is_enabled', 1);
            })
            ->join('orders as o', function ($j) {
                $j->on('o.amazon_order_id', '=', 'oi.amazon_order_id')
                  ->on('o.marketplace_id', '=', 'oi.marketplace_id');
            })
            ->where('o.marketplace_id', $marketplaceId)
            ->where('o.order_status', 'Shipped')
            ->whereNotNull('o.purchase_date')
            ->whereRaw(
                'DATE_ADD(o.purchase_date, INTERVAL rrs.delay_days DAY) <= ?',
                [$now]
            )
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('review_request_queue as q')
                  ->whereColumn('q.amazon_order_id', 'o.amazon_order_id')
                  ->whereColumn('q.marketplace_id', 'o.marketplace_id')
                  ->whereColumn('q.asin', 'oi.asin');
            })
            ->orderBy('o.purchase_date')
            ->limit($limit)
            ->select([
                'o.user_id',
                'o.marketplace_id',
                'o.amazon_order_id',
                'oi.asin',
                'o.purchase_date',
                'rrs.delay_days',
                'rrs.process_hour',
            ])
            ->get();

        if ($debug) {
            $this->line('eligible_rows=' . $rows->count());
        }

        if ($rows->isEmpty()) {
            if ($debug) {
                $this->info('DONE: nothing to dispatch');
            }
            return Command::SUCCESS;
        }

        /*
         |--------------------------------------------------------------------------
         | 2️⃣ Кладём в очередь
         |--------------------------------------------------------------------------
         */

        $inserted = 0;

        foreach ($rows as $row) {
            DB::beginTransaction();

            try {
                $runAfter = Carbon::parse($row->purchase_date)
                    ->addDays($row->delay_days)
                    ->setHour($row->process_hour)
                    ->setMinute(0)
                    ->setSecond(0);

                DB::table('review_request_queue')->insert([
                    'user_id'         => $row->user_id,
                    'marketplace_id'  => $row->marketplace_id,
                    'amazon_order_id' => $row->amazon_order_id,
                    'asin'            => $row->asin,
                    'status'          => 'pending',
                    'attempts'        => 0,
                    'run_after'       => $runAfter,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                DB::commit();
                $inserted++;

                if ($debug) {
                    $this->line(
                        "queued order={$row->amazon_order_id} asin={$row->asin} run_after={$runAfter}"
                    );
                }

            } catch (\Throwable $e) {
                DB::rollBack();

                if ($debug) {
                    $this->error(
                        "ERROR order={$row->amazon_order_id} asin={$row->asin}: {$e->getMessage()}"
                    );
                }
            }
        }

        if ($debug) {
            $this->info("DONE: inserted={$inserted}");
        }

        return Command::SUCCESS;
    }
}
