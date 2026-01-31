<?php

namespace App\Http\Middleware;

use App\Models\Marketplace;
use App\Models\UserMarketplace;
use Closure;
use Illuminate\Http\Request;

class SetActiveMarketplace
{
    public function handle(Request $request, Closure $next)
    {
        // если пользователь не залогинен — не трогаем
        if (! auth()->check()) {
            return $next($request);
        }

        $active = session('active_marketplace');
        $activeId = $active !== null ? (int) $active : null;

        // 1️⃣ Если активный есть и валиден — просто гарантируем code
        if ($activeId && $this->isValid($activeId)) {
            $this->ensureMarketplaceCode($activeId);

            return $next($request);
        }

        // 2️⃣ Иначе берём первый enabled marketplace пользователя
        $fallbackId = UserMarketplace::query()
            ->where('user_id', auth()->id())
            ->where('is_enabled', true)
            ->orderBy('marketplace_id')
            ->value('marketplace_id');

        if ($fallbackId !== null) {
            $fallbackId = (int) $fallbackId;

            session([
                'active_marketplace' => $fallbackId,
            ]);

            $this->ensureMarketplaceCode($fallbackId);
        }

        return $next($request);
    }

    protected function isValid(int $marketplaceId): bool
    {
        return UserMarketplace::query()
            ->where('user_id', auth()->id())
            ->where('marketplace_id', $marketplaceId)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Гарантирует наличие active_marketplace_code в session
     */
    protected function ensureMarketplaceCode(int $marketplaceId): void
    {
        // если уже есть — не дёргаем БД повторно
        if (session()->has('active_marketplace_code')) {
            return;
        }

        $code = Marketplace::query()
            ->where('id', $marketplaceId)
            ->value('code');

        if ($code) {
            session(['active_marketplace_code' => $code]);
        }
    }
}
