<?php

    use Illuminate\Support\Facades\Route;
    use Illuminate\Http\Request;

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

    Route::get('/marketplace/switch/{id}', function (int $id) {
        session(['active_marketplace' => $id]);

        return redirect()->back();
    })->name('marketplace.switch.sidebar');
    
    Route::post('/marketplace/switch', function (Request $request) {
    session([
        'active_marketplace' => (int) $request->marketplace_id,
    ]);

    return redirect()->back();
})->name('marketplace.switch.post');