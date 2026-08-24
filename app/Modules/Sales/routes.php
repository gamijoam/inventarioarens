<?php

use App\Modules\Sales\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::prefix('sales')->group(function (): void {
    Route::get('/', [SaleController::class, 'index']);
    Route::post('/', [SaleController::class, 'store'])->middleware('idempotency');
    Route::get('{sale}', [SaleController::class, 'show']);
    Route::patch('{sale}/confirm', [SaleController::class, 'confirm'])->middleware('idempotency');
    Route::patch('{sale}/cancel', [SaleController::class, 'cancel'])->middleware('idempotency');
});
