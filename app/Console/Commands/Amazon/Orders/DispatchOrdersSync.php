<?php

namespace App\Console\Commands\Amazon\Orders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DispatchOrdersSync extends Command
{
    protected $signature = 'amazon:orders:dispatch
        {--user_id=}
        {--marketplace_id=}
        {--force : Ignore last successful sync}';

    protected $description = 'Dispatch Amazon orders sync jobs';

    private const OVERLAP_DAYS  = 3;
    private const FALLBACK_DAYS = 14;

    public function handle(): int
    {
        $userId        = $this->option('user_id');
        $marketplaceId = $this->option('marketplace_id');
        $force         = (bool) $this->option('force');

        if (!$userId || !$marketplaceId) {
            $this->error('--user_id and --marketplace_id are required');
            return self::FAILURE;
        }

        // 1️⃣ Проверяем, нет ли уже активного sync
        $exists = DB::table('orders_sync')
            ->where('user_id', $userId)
            ->where('marketplace_id', $marketplaceId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($exists) {
            $this->warn('Orders sync already pending or running');
            return self::SUCCESS;
        }

        $now = Carbon::now();

        // 2️⃣ Последний успешный sync
        $lastCompleted = null;

        if (!$force) {
            $lastCompleted = DB::table('orders_sync')
                ->where('user_id', $userId)
                ->where('marketplace_id', $marketplaceId)
                ->where('status', 'completed')
                ->orderByDesc('finished_at')
                ->value('finished_at');
        }

        // 3️⃣ Расчёт from_date
        if ($force || !$lastCompleted) {
            $from = $now->copy()->subDays(self::FALLBACK_DAYS);
        } else {
            $from = Carbon::parse($lastCompleted)
                ->subDays(self::OVERLAP_DAYS);
        }

        $to = $now;

        // safety guard
        if ($from->greaterThanOrEqualTo($to)) {
            $from = $to->copy()->subDay();
        }

        // 4️⃣ Создаём запись orders_sync
        $id = DB::table('orders_sync')->insertGetId([
            'user_id'        => $userId,
            'marketplace_id' => $marketplaceId,
            'from_date'      => $from,
            'to_date'        => $to,
            'is_forced'      => $force ? 1 : 0,
            'status'         => 'pending',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->info("Amazon orders sync dispatched");
        $this->line("orders_sync.id = {$id}");
        $this->line("from = {$from->toDateTimeString()}");
        $this->line("to   = {$to->toDateTimeString()}");

        return self::SUCCESS;
    }
}
