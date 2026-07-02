<?php

use App\Modules\Currency\Controllers\ExchangeRateController;
use App\Modules\Currency\Controllers\ExchangeRateTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('currency')->group(function (): void {
    Route::apiResource('rate-types', ExchangeRateTypeController::class)
        ->parameters(['rate-types' => 'type']);

    Route::get('rates/current', [ExchangeRateController::class, 'current']);
    Route::patch('rates/{rate}/activate', [ExchangeRateController::class, 'activate']);
    Route::apiResource('rates', ExchangeRateController::class)
        ->only(['index', 'store', 'show'])
        ->parameters(['rates' => 'rate']);
});
