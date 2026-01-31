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

        if ($debug) {
            $this->info('=== REVIEW REQUEST DISPATCHER ===');
            $this->line('limit=' . $limit);
            $this->line('marketplace_id=' . $marketplaceId);
            $this->line('now=' . $now->toDateTimeString());
        }

        /*
         |--------------------------------------------------------------------------
         | 1) Eligible orders (ORDER-first)
         |--------------------------------------------------------------------------
         | Conditions:
         | - order_status = Shipped
         | - latest_delivery_date IS NOT NULL
         | - has at least one ASIN with enabled review settings
         | - user delay passed (delivery + delay_days <= now OR in future)
         | - NOT already in review_request_queue
         |
         | Amazon hard gate:
         | - now <= delivery + 30 days   (otherwise DEAD, never enqueue)
         |
         | We also select:
         | - trigger_asin (deterministic, MIN)
         | - MIN(delay_days / process_hour) for earliest scheduling
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
            ->whereNotNull('o.latest_delivery_date')

            // Amazon hard upper bound: >30 days since delivery => never enqueue
            ->whereRaw(
                'DATE_ADD(o.latest_delivery_date, INTERVAL 30 DAY) >= ?',
                [$now]
            )

            // User delay must have passed OR will be scheduled into future
            ->whereRaw(
                'DATE_ADD(o.latest_delivery_date, INTERVAL rrs.delay_days DAY) <= DATE_ADD(?, INTERVAL 30 DAY)',
                [$now]
            )

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

                // Base user intention
                $runAfter = $delivery
                    ->copy()
                    ->addDays((int) $row->delay_days)
                    ->setHour((int) ($row->process_hour ?? 0))
                    ->setMinute(0)
                    ->setSecond(0);

                // Clamp to Amazon window
                if ($runAfter->lt($amazonMin)) {
                    if ($debug) {
                        $this->line(
                            "bump order={$row->amazon_order_id} from {$runAfter} to amazon_min {$amazonMin}"
                        );
                    }
                    $runAfter = $amazonMin->copy()
                        ->setHour((int) ($row->process_hour ?? 0))
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
                            "queued order={$row->amazon_order_id} asin={$asin} run_after={$runAfter}"
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
