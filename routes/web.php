<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\LocaleSwitchController;

Route::get('/locale/{locale}', LocaleSwitchController::class)
    ->name('locale.switch');

use App\Http\Controllers\MarketplaceSwitchController;

Route::get('/marketplace/{marketplace}', MarketplaceSwitchController::class)
    ->name('marketplace.switch');
