<?php

    use Illuminate\Support\Facades\Route;

    Route::get('/', function () {
        return view('welcome');
    });

    //use App\Http\Controllers\LocaleSwitchController;

    Route::get('/locale/{locale}', function (string $locale) {
        abort_unless(in_array($locale, ['en', 'fr', 'de'], true), 404);
        session(['locale' => $locale]);
        return redirect()->back();
    })->name('locale.switch');

    Route::get('/marketplace/{marketplace}', function (int $marketplace) {
        session(['active_marketplace' => $marketplace]);
        return redirect()->back();
    })->name('marketplace.switch');

