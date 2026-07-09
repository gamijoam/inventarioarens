<?php

use App\Modules\POS\Controllers\PosOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->group(function (): void {
    Route::get('orders', [PosOrderController::class, 'index']);
    Route::post('checkouts', [PosOrderController::class, 'checkout']);
    Route::get('orders/{posOrder}', [PosOrderController::class, 'show']);
    Route::post('orders/{posOrder}/payments', [PosOrderController::class, 'addPayments']);
    Route::post('orders/{posOrder}/cancel', [PosOrderController::class, 'cancel']);
});
