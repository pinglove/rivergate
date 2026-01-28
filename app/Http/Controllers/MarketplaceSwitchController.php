<?php

namespace App\Http\Controllers;

use App\Models\UserMarketplace;
use Illuminate\Http\RedirectResponse;

class MarketplaceSwitchController
{
    public function __invoke(int $marketplaceId): RedirectResponse
    {
        $allowed = UserMarketplace::query()
            ->where('user_id', auth()->id())
            ->where('marketplace_id', $marketplaceId)
            ->where('is_enabled', true)
            ->exists();

        abort_unless($allowed, 403);

        session(['active_marketplace' => $marketplaceId]);

        return redirect()->back();
    }
}
