<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class LocaleSwitchController
{
    public function __invoke(string $locale): RedirectResponse
    {
        if (!in_array($locale, ['en', 'fr', 'de'])) {
            abort(404);
        }

        session(['locale' => $locale]);

        return redirect()->back();
    }
}
