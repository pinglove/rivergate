<?php

namespace App\Console\Commands\Amazon\Orders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DispatchOrdersSync extends Command
{
    protected $signature = 'amazon:orders:dispatch
        {--user_id= : Optional user filter}
        {--marketplace_id= : Optional marketplace filter}
        {--force : Ignore last successful sync}';

    protected $description = 'Dispatch Amazon orders sync jobs';

    /**
     * Days overlap to avoid missing late updates
     */
    private const OVERLAP_DAYS = 3;

    /**
     * Initial fallback window if no successful sync exists
     */
    private const FALLBACK_DAYS = 14;

    public function handle(): int
    {
        $filterUserId        = $this->option('user_id');
        $filterMarketplaceId = $this->option('marketplace_id');
        $force               = (bool) $this->option('force');

        /**
         * 1️⃣ Resolve user × marketplace pairs
         */
        if ($filterUserId && $filterMarketplaceId) {
            $pairs = collect([[
                'user_id'        => (int) $filterUserId,
                'marketplace_id' => (int) $filterMarketplaceId,
            ]]);
        } else {
            $pairs = DB::table('user_marketplaces')
                ->where('is_enabled', 1)
                ->select('user_id', 'marketplace_id')
                ->get();
        }

        if ($pairs->isEmpty()) {
            $this->info('No user/marketplace pairs found');
            return self::SUCCESS;
        }

        $now        = Carbon::now();
        $dispatched = 0;

        foreach ($pairs as $pair) {
            $userId        = (int) $pair->user_id;
            $marketplaceId = (int) $pair->marketplace_id;

            /**
             * 2️⃣ HARD GATE: active RefreshToken must exist
             */
            $hasToken = DB::table('amazon_refresh_tokens')
                ->where('user_id', $userId)
                ->where('marketplace_id', $marketplaceId)
                ->where('status', 'active')
                ->exists();

            if (! $hasToken) {
                continue;
            }

            /**
             * 3️⃣ Do not overlap active syncs
             */
            $hasActiveSync = DB::table('orders_sync')
                ->where('user_id', $userId)
                ->where('marketplace_id', $marketplaceId)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if ($hasActiveSync) {
                continue;
            }

            /**
             * 4️⃣ Determine date window
             */
            $lastCompletedAt = null;

            if (! $force) {
                $lastCompletedAt = DB::table('orders_sync')
                    ->where('user_id', $userId)
                    ->where('marketplace_id', $marketplaceId)
                    ->where('status', 'completed')
                    ->orderByDesc('finished_at')
                    ->value('finished_at');
            }

            if ($force || ! $lastCompletedAt) {
                $from = $now->copy()->subDays(self::FALLBACK_DAYS);
            } else {
                $from = Carbon::parse($lastCompletedAt)
                    ->subDays(self::OVERLAP_DAYS);
            }

            $to = $now;

            if ($from->greaterThanOrEqualTo($to)) {
                $from = $to->copy()->subDay();
            }

            /**
             * 5️⃣ Create sync job
             */
            DB::table('orders_sync')->insert([
                'user_id'        => $userId,
                'marketplace_id' => $marketplaceId,
                'from_date'      => $from,
                'to_date'        => $to,
                'is_forced'      => $force ? 1 : 0,
                'status'         => 'pending',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            $dispatched++;
        }

        $this->info("Amazon orders sync dispatched: {$dispatched}");

        return self::SUCCESS;
    }
}
