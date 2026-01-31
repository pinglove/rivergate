<?php

namespace App\Console\Commands\Amazon\Reviews;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DispatchReviewRequests extends Command
{
    protected $signature = 'reviews:dispatch
        {--limit=500 : Max orders to enqueue per run}
        {--marketplace_id= : Marketplace ID (required in CLI; fallback to session active marketplace if present)}
        {--debug : Verbose console output}';

    protected $description = 'Dispatch Amazon Request a Review jobs (ORDER-first; Amazon 5–30 days gate; store trigger ASIN)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $debug = (bool) $this->option('debug');

        $marketplaceId = $this->option('marketplace_id')
            ? (int) $this->option('marketplace_id')
            : (int) session('active_marketplace');

        if (! $marketplaceId) {
            $this->error('marketplace_id is required (or active marketplace must be set)');
            return Command::FAILURE;
        }

        $now = Carbon::now();
        $windowStart = $now->copy()->subDays(25);

        if ($debug) {
            $this->info('=== REVIEW REQUEST DISPATCHER ===');
            $this->line('limit=' . $limit);
            $this->line('marketplace_id=' . $marketplaceId);
            $this->line('now=' . $now->toDateTimeString());
            $this->line('window_start=' . $windowStart->toDateTimeString());
            $this->line('window_end=' . $now->toDateTimeString());
        }

        /*
         |--------------------------------------------------------------------------
         | 1) Build main query
         |--------------------------------------------------------------------------
         */

        $baseQuery = DB::table('orders as o')
            ->join('orders_items as oi', function ($j) {
                $j->on('oi.amazon_order_id', '=', 'o.amazon_order_id')
                  ->on('oi.marketplace_id', '=', 'o.marketplace_id');
            })
            ->join('asins_asins as a', function ($j) {
                $j->on('a.asin', '=', 'oi.asin')
                  ->on('a.marketplace_id', '=', 'oi.marketplace_id');
            })
            ->join('review_request_settings as rrs', function ($j) {
                $j->on('rrs.asin_id', '=', 'a.id')
                  ->on('rrs.marketplace_id', '=', 'o.marketplace_id')
                  ->where('rrs.is_enabled', 1);
            })
            ->where('o.marketplace_id', $marketplaceId)
            ->where('o.order_status', 'Shipped')
            ->whereNotNull('o.latest_delivery_date')
            ->where('o.latest_delivery_date', '<=', $now)
            ->where('o.latest_delivery_date', '>=', $windowStart)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('review_request_queue as q')
                  ->whereColumn('q.amazon_order_id', 'o.amazon_order_id')
                  ->whereColumn('q.marketplace_id', 'o.marketplace_id');
            })
            ->groupBy(
                'o.user_id',
                'o.marketplace_id',
                'o.amazon_order_id',
                'o.latest_delivery_date'
            )
            ->orderBy('o.latest_delivery_date')
            ->limit($limit)
            ->select([
                'o.user_id',
                'o.marketplace_id',
                'o.amazon_order_id',
                'o.latest_delivery_date',
                DB::raw('MIN(rrs.delay_days) as delay_days'),
                DB::raw('MIN(rrs.process_hour) as process_hour'),
                DB::raw('MIN(oi.asin) as trigger_asin'),
            ]);

        if ($debug) {
            $this->info('--- MAIN SQL (orders selection) ---');
            $this->line($baseQuery->toSql());
            $this->line('bindings=' . json_encode($baseQuery->getBindings(), JSON_UNESCAPED_UNICODE));
        }

        /*
         |--------------------------------------------------------------------------
         | 1b) Debug pipeline counters (where it dies)
         |--------------------------------------------------------------------------
         */

        if ($debug) {
            $this->info('--- DEBUG PIPELINE COUNTERS ---');

            // Step 0: shipped + delivery not null + marketplace
            $q0 = DB::table('orders as o')
                ->where('o.marketplace_id', $marketplaceId)
                ->where('o.order_status', 'Shipped')
                ->whereNotNull('o.latest_delivery_date');

            $c0 = (clone $q0)->count();
            $this->line("step0 orders (mp+shipped+delivery!=null) = {$c0}");

            $s0 = (clone $q0)
                ->orderByDesc('o.latest_delivery_date')
                ->limit(5)
                ->get(['o.amazon_order_id', 'o.latest_delivery_date', 'o.purchase_date', 'o.last_updated_date']);
            $this->line('step0 sample=' . $s0->toJson(JSON_UNESCAPED_UNICODE));

            // Step 1: window filter
            $q1 = (clone $q0)
                ->where('o.latest_delivery_date', '<=', $now)
                ->where('o.latest_delivery_date', '>=', $windowStart);

            $c1 = (clone $q1)->count();
            $this->line("step1 orders (delivery in window) = {$c1}");

            $s1 = (clone $q1)
                ->orderByDesc('o.latest_delivery_date')
                ->limit(5)
                ->get(['o.amazon_order_id', 'o.latest_delivery_date']);
            $this->line('step1 sample=' . $s1->toJson(JSON_UNESCAPED_UNICODE));

            // Step 2: join orders_items existence
            $q2 = DB::table('orders as o')
                ->join('orders_items as oi', function ($j) {
                    $j->on('oi.amazon_order_id', '=', 'o.amazon_order_id')
                      ->on('oi.marketplace_id', '=', 'o.marketplace_id');
                })
                ->where('o.marketplace_id', $marketplaceId)
                ->where('o.order_status', 'Shipped')
                ->whereNotNull('o.latest_delivery_date')
                ->where('o.latest_delivery_date', '<=', $now)
                ->where('o.latest_delivery_date', '>=', $windowStart);

            $c2 = (clone $q2)->distinct()->count('o.amazon_order_id');
            $this->line("step2 orders (after join orders_items) distinct orders = {$c2}");

            $s2 = (clone $q2)
                ->orderByDesc('o.latest_delivery_date')
                ->limit(5)
                ->get(['o.amazon_order_id', 'o.latest_delivery_date', 'oi.asin']);
            $this->line('step2 sample=' . $s2->toJson(JSON_UNESCAPED_UNICODE));

            // Step 3: join asins_asins existence
            $q3 = DB::table('orders as o')
                ->join('orders_items as oi', function ($j) {
                    $j->on('oi.amazon_order_id', '=', 'o.amazon_order_id')
                      ->on('oi.marketplace_id', '=', 'o.marketplace_id');
                })
                ->join('asins_asins as a', function ($j) {
                    $j->on('a.asin', '=', 'oi.asin')
                      ->on('a.marketplace_id', '=', 'oi.marketplace_id');
                })
                ->where('o.marketplace_id', $marketplaceId)
                ->where('o.order_status', 'Shipped')
                ->whereNotNull('o.latest_delivery_date')
                ->where('o.latest_delivery_date', '<=', $now)
                ->where('o.latest_delivery_date', '>=', $windowStart);

            $c3 = (clone $q3)->distinct()->count('o.amazon_order_id');
            $this->line("step3 orders (after join asins_asins) distinct orders = {$c3}");

            $s3 = (clone $q3)
                ->orderByDesc('o.latest_delivery_date')
                ->limit(5)
                ->get(['o.amazon_order_id', 'o.latest_delivery_date', 'oi.asin', 'a.id as asin_id']);
            $this->line('step3 sample=' . $s3->toJson(JSON_UNESCAPED_UNICODE));

            // Step 4: join review_request_settings enabled existence
            $q4 = DB::table('orders as o')
                ->join('orders_items as oi', function ($j) {
                    $j->on('oi.amazon_order_id', '=', 'o.amazon_order_id')
                      ->on('oi.marketplace_id', '=', 'o.marketplace_id');
                })
                ->join('asins_asins as a', function ($j) {
                    $j->on('a.asin', '=', 'oi.asin')
                      ->on('a.marketplace_id', '=', 'oi.marketplace_id');
                })
                ->join('review_request_settings as rrs', function ($j) {
                    $j->on('rrs.asin_id', '=', 'a.id')
                      ->on('rrs.marketplace_id', '=', 'o.marketplace_id')
                      ->where('rrs.is_enabled', 1);
                })
                ->where('o.marketplace_id', $marketplaceId)
                ->where('o.order_status', 'Shipped')
                ->whereNotNull('o.latest_delivery_date')
                ->where('o.latest_delivery_date', '<=', $now)
                ->where('o.latest_delivery_date', '>=', $windowStart);

            $c4 = (clone $q4)->distinct()->count('o.amazon_order_id');
            $this->line("step4 orders (after join review_request_settings enabled) distinct orders = {$c4}");

            $s4 = (clone $q4)
                ->orderByDesc('o.latest_delivery_date')
                ->limit(5)
                ->get([
                    'o.amazon_order_id',
                    'o.latest_delivery_date',
                    'oi.asin',
                    'a.id as asin_id',
                    'rrs.id as rrs_id',
                    'rrs.delay_days',
                    'rrs.process_hour',
                    'rrs.is_enabled',
                ]);
            $this->line('step4 sample=' . $s4->toJson(JSON_UNESCAPED_UNICODE));

            // Step 5: not already queued
            $q5 = (clone $q4)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('review_request_queue as q')
                      ->whereColumn('q.amazon_order_id', 'o.amazon_order_id')
                      ->whereColumn('q.marketplace_id', 'o.marketplace_id');
                });

            $c5 = (clone $q5)->distinct()->count('o.amazon_order_id');
            $this->line("step5 orders (after NOT EXISTS queue) distinct orders = {$c5}");

            $s5 = (clone $q5)
                ->orderByDesc('o.latest_delivery_date')
                ->limit(5)
                ->get(['o.amazon_order_id', 'o.latest_delivery_date', 'oi.asin']);
            $this->line('step5 sample=' . $s5->toJson(JSON_UNESCAPED_UNICODE));

            // Extra: show if queue already has recent orders
            $qCount = DB::table('review_request_queue')
                ->where('marketplace_id', $marketplaceId)
                ->count();
            $this->line("queue total rows for marketplace={$marketplaceId} = {$qCount}");

            $qRecent = DB::table('review_request_queue')
                ->where('marketplace_id', $marketplaceId)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['amazon_order_id', 'asin', 'status', 'run_after', 'created_at']);
            $this->line('queue recent=' . $qRecent->toJson(JSON_UNESCAPED_UNICODE));
        }

        /*
         |--------------------------------------------------------------------------
         | 1c) Run main query
         |--------------------------------------------------------------------------
         */

        $rows = $baseQuery->get();

        if ($debug) {
            $this->line('eligible_orders=' . $rows->count());
        }

        if ($rows->isEmpty()) {
            if ($debug) {
                $this->info('DONE: nothing to dispatch');
            }
            return Command::SUCCESS;
        }

        /*
         |--------------------------------------------------------------------------
         | 2) Insert into review_request_queue
         |--------------------------------------------------------------------------
         | UNIQUE(marketplace_id, amazon_order_id) must exist
         |--------------------------------------------------------------------------
         */

        $inserted = 0;
        $ignored  = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($rows as $row) {
            try {
                $delivery = Carbon::parse($row->latest_delivery_date);

                $amazonMin = $delivery->copy()->addDays(5);
                $amazonMax = $delivery->copy()->addDays(30);

                $delayDays   = (int) ($row->delay_days ?? 0);
                $processHour = (int) ($row->process_hour ?? 0);

                // Base user intention
                $runAfter = $delivery
                    ->copy()
                    ->addDays($delayDays)
                    ->setHour($processHour)
                    ->setMinute(0)
                    ->setSecond(0);

                // Clamp to Amazon window (5..30 days after delivery)
                if ($runAfter->lt($amazonMin)) {
                    if ($debug) {
                        $this->line(
                            "bump order={$row->amazon_order_id} from {$runAfter} to amazon_min {$amazonMin}"
                        );
                    }
                    $runAfter = $amazonMin->copy()
                        ->setHour($processHour)
                        ->setMinute(0)
                        ->setSecond(0);
                }

                if ($runAfter->gt($amazonMax)) {
                    $skipped++;
                    if ($debug) {
                        $this->line(
                            "skip order={$row->amazon_order_id} (run_after {$runAfter} > amazon_max {$amazonMax})"
                        );
                    }
                    continue;
                }

                $asin = trim((string) ($row->trigger_asin ?? ''));
                if ($asin === '') {
                    $errors++;
                    if ($debug) {
                        $this->error("ERROR order={$row->amazon_order_id}: trigger_asin empty");
                    }
                    continue;
                }

                $result = DB::table('review_request_queue')->insertOrIgnore([
                    'user_id'         => $row->user_id,
                    'marketplace_id'  => $row->marketplace_id,
                    'amazon_order_id' => $row->amazon_order_id,
                    'asin'            => $asin,
                    'status'          => 'pending',
                    'attempts'        => 0,
                    'run_after'       => $runAfter,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                if ($result === 1) {
                    $inserted++;
                    if ($debug) {
                        $this->line(
                            "queued order={$row->amazon_order_id} asin={$asin} delivery={$delivery} run_after={$runAfter}"
                        );
                    }
                } else {
                    $ignored++;
                    if ($debug) {
                        $this->line(
                            "ignored duplicate order={$row->amazon_order_id}"
                        );
                    }
                }

            } catch (\Throwable $e) {
                $errors++;
                if ($debug) {
                    $this->error(
                        "ERROR order={$row->amazon_order_id}: {$e->getMessage()}"
                    );
                }
            }
        }

        if ($debug) {
            $this->info(
                "DONE: inserted={$inserted} ignored={$ignored} skipped={$skipped} errors={$errors}"
            );
        }

        return Command::SUCCESS;
    }
}
