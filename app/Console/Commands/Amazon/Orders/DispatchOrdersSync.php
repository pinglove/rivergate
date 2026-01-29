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

    private const OVERLAP_DAYS  = 3;
    private const FALLBACK_DAYS = 14;

    public function handle(): int
    {
        $userId        = $this->option('user_id');
        $marketplaceId = $this->option('marketplace_id');
        $force         = (bool) $this->option('force');

        // 1️⃣ Определяем пары user × marketplace
        if ($userId && $marketplaceId) {
            $pairs = collect([[
                'user_id'        => (int) $userId,
                'marketplace_id' => (int) $marketplaceId,
            ]]);
        } else {
            // ⚠️ адаптируй имя таблицы при необходимости
            $pairs = DB::table('user_marketplaces')
                ->where('is_enabled', 1)
                ->select('user_id', 'marketplace_id')
                ->get();
        }

        if ($pairs->isEmpty()) {
            $this->warn('No user/marketplace pairs found');
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $dispatched = 0;

        foreach ($pairs as $pair) {
            $userId        = (int) $pair->user_id;
            $marketplaceId = (int) $pair->marketplace_id;

            // 2️⃣ Проверка активного sync
            $exists = DB::table('orders_sync')
                ->where('user_id', $userId)
                ->where('marketplace_id', $marketplaceId)
                ->whereIn('status', ['pending', 'running'])
                ->exists();

            if ($exists) {
                continue;
            }

            // 3️⃣ Последний успешный sync
            $lastCompleted = null;

            if (! $force) {
                $lastCompleted = DB::table('orders_sync')
                    ->where('user_id', $userId)
                    ->where('marketplace_id', $marketplaceId)
                    ->where('status', 'completed')
                    ->orderByDesc('finished_at')
                    ->value('finished_at');
            }

            // 4️⃣ Расчёт from / to
            if ($force || ! $lastCompleted) {
                $from = $now->copy()->subDays(self::FALLBACK_DAYS);
            } else {
                $from = Carbon::parse($lastCompleted)
                    ->subDays(self::OVERLAP_DAYS);
            }

            $to = $now;

            if ($from->greaterThanOrEqualTo($to)) {
                $from = $to->copy()->subDay();
            }

            // 5️⃣ Создание sync
            DB::table('orders_sync')->insert([
                'user_id'        => $userId,
                'marketplace_id' => $marketplaceId,
                'from_date'      => $from,
                'to_date'        => $to,
                'is_forced'      => $force ? 1 : 0,
                'status'         => 'pending',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            $dispatched++;
        }

        $this->info("Amazon orders sync dispatched: {$dispatched}");

        return self::SUCCESS;
    }
}
