<?php

namespace App\Http\Middleware;

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

        // 1️⃣ Если активный есть и валиден — ок
        if ($activeId && $this->isValid($activeId)) {
            return $next($request);
        }

        // 2️⃣ Иначе берём первый enabled marketplace пользователя
        $fallback = UserMarketplace::query()
            ->where('user_id', auth()->id())
            ->where('is_enabled', true)
            ->orderBy('marketplace_id')
            ->value('marketplace_id');

        if ($fallback !== null) {
            session(['active_marketplace' => (int) $fallback]);
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
}
