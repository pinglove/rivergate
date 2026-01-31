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

    protected $description = 'Dispatch Amazon Request a Review jobs (ORDER-first; 1 request per order; store trigger ASIN)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $debug = (bool) $this->option('debug');

        // In CLI session is usually empty, but keep fallback for your current UX.
        $marketplaceId = $this->option('marketplace_id')
            ? (int) $this->option('marketplace_id')
            : (int) session('active_marketplace');

        if (!$marketplaceId) {
            $this->error('marketplace_id is required (or active marketplace must be set)');
            return Command::FAILURE;
        }

        $now = Carbon::now();

        if ($debug) {
            $this->info('=== REVIEW REQUEST DISPATCHER (ORDER-FIRST) ===');
            $this->line('limit=' . $limit);
            $this->line('marketplace_id=' . $marketplaceId);
            $this->line('now=' . $now->toDateTimeString());
        }

        /*
         |--------------------------------------------------------------------------
         | 1) Eligible orders (ORDER-first):
         |    - order Shipped
         |    - purchase_date not null
         |    - order has at least one item ASIN with enabled review_request_settings
         |    - delay passed: purchase_date + delay_days <= now
         |    - order not yet in review_request_queue (unique by marketplace_id + amazon_order_id)
         |
         | Also store "trigger_asin" for explainability:
         |    - chosen deterministically from eligible rows (MIN(oi.asin))
         |
         | delay_days/process_hour:
         |    - MIN among eligible ASIN rows => earliest allowed scheduling
         |--------------------------------------------------------------------------
         */

        $rows = DB::table('orders as o')
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
            ->whereNotNull('o.purchase_date')
            ->whereRaw(
                'DATE_ADD(o.purchase_date, INTERVAL rrs.delay_days DAY) <= ?',
                [$now]
            )
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('review_request_queue as q')
                  ->whereColumn('q.amazon_order_id', 'o.amazon_order_id')
                  ->whereColumn('q.marketplace_id', 'o.marketplace_id');
            })
            ->groupBy('o.user_id', 'o.marketplace_id', 'o.amazon_order_id', 'o.purchase_date')
            ->orderBy('o.purchase_date')
            ->limit($limit)
            ->select([
                'o.user_id',
                'o.marketplace_id',
                'o.amazon_order_id',
                'o.purchase_date',
                DB::raw('MIN(rrs.delay_days) as delay_days'),
                DB::raw('MIN(rrs.process_hour) as process_hour'),
                DB::raw('MIN(oi.asin) as trigger_asin'),
            ])
            ->get();

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
         | 2) Insert into queue (order-level)
         |
         | DB protection MUST exist:
         |   UNIQUE(marketplace_id, amazon_order_id)
         |
         | We use insertOrIgnore() to be race-safe if dispatcher runs concurrently.
         |--------------------------------------------------------------------------
         */

        $inserted = 0;
        $ignored = 0;
        $errors = 0;

        foreach ($rows as $row) {
            try {
                $processHour = (int) ($row->process_hour ?? 0);

                $runAfter = Carbon::parse($row->purchase_date)
                    ->addDays((int) $row->delay_days)
                    ->setHour($processHour)
                    ->setMinute(0)
                    ->setSecond(0);

                $asin = trim((string) ($row->trigger_asin ?? ''));

                // If asin is required by schema, do not enqueue broken rows.
                if ($asin === '') {
                    $errors++;
                    if ($debug) {
                        $this->error("ERROR order={$row->amazon_order_id}: trigger_asin is empty");
                    }
                    continue;
                }

                $result = DB::table('review_request_queue')->insertOrIgnore([
                    'user_id'         => $row->user_id,
                    'marketplace_id'  => $row->marketplace_id,
                    'amazon_order_id' => $row->amazon_order_id,
                    'asin'            => $asin, // ✅ explainability: which ASIN made it eligible
                    'status'          => 'pending',
                    'attempts'        => 0,
                    'run_after'       => $runAfter,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                if ($result === 1) {
                    $inserted++;
                    if ($debug) {
                        $this->line("queued order={$row->amazon_order_id} asin={$asin} run_after={$runAfter}");
                    }
                } else {
                    $ignored++;
                    if ($debug) {
                        $this->line("ignored (duplicate) order={$row->amazon_order_id}");
                    }
                }
            } catch (\Throwable $e) {
                $errors++;
                if ($debug) {
                    $this->error("ERROR order={$row->amazon_order_id}: {$e->getMessage()}");
                }
            }
        }

        if ($debug) {
            $this->info("DONE: inserted={$inserted} ignored={$ignored} errors={$errors}");
        }

        return Command::SUCCESS;
    }
}
