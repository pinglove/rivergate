<?php

namespace App\Support\Asins;

use App\Models\Amazon\Asins\AsinUserMpSync;
use Carbon\Carbon;

class AsinCatalogSyncPolicy
{
    /**
     * Через сколько часов после УСПЕШНОЙ синхронизации
     * можно запускать новую
     */
    public const SUCCESS_COOLDOWN_HOURS = 12;

    /**
     * Через сколько часов после ERROR
     * можно разрешить новый запуск
     */
    public const ERROR_COOLDOWN_HOURS = 0;

    /**
     * Статусы, которые считаются "активной синхронизацией"
     */
    private const ACTIVE_STATUSES = [
        'pending',
        'worker_started',
        'worker_fetching',
        'worker_fetched',
        'analyzer_started',
        'analyzer_validating',
        'analyzer_applying',
    ];

    public static function canStart(int $userId, int $marketplaceId): bool
    {
        // 1️⃣ Есть активная синхронизация — нельзя
        $active = AsinUserMpSync::query()
            ->where('user_id', $userId)
            ->where('marketplace_id', $marketplaceId)
            ->whereIn('status', [
                'pending',
                'worker_started',
                'worker_fetching',
                'worker_fetched',
                'analyzer_started',
                'analyzer_validating',
                'analyzer_applying',
            ])
            ->exists();

        if ($active) {
            return false;
        }

        // 2️⃣ Есть успешная моложе cooldown — нельзя
        $lastCompleted = AsinUserMpSync::query()
            ->where('user_id', $userId)
            ->where('marketplace_id', $marketplaceId)
            ->where('status', 'completed')
            ->orderByDesc('updated_at')
            ->first();

        if ($lastCompleted) {
            $nextAllowedAt = Carbon::parse($lastCompleted->finished_at)
                ->addHours(self::SUCCESS_COOLDOWN_HOURS);


            if (now()->lessThan($nextAllowedAt)) {
                return false;
            }
        }

        return true;
    }

}
