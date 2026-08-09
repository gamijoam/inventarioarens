<?php

use App\Modules\POS\Controllers\PosBootstrapController;
use App\Modules\POS\Controllers\PosOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->group(function (): void {
    Route::get('bootstrap', PosBootstrapController::class);
    Route::get('orders', [PosOrderController::class, 'index']);
    // Armar una orden (vendedor): sin caja, sin pagos, sin IMEI obligatorio.
    // Idempotency-Key: si el cliente reintenta el mismo POST con la misma
    // key, devolvemos la respuesta original sin re-ejecutar la orden.
    Route::post('orders', [PosOrderController::class, 'hold'])
        ->middleware('idempotency');
    // Idempotency-Key: si el cliente reintenta el mismo POST con la misma
    // key, devolvemos la respuesta original sin re-ejecutar la venta.
    Route::post('checkouts', [PosOrderController::class, 'checkout'])
        ->middleware('idempotency');
    Route::get('orders/{posOrder}', [PosOrderController::class, 'show']);
    Route::post('orders/{posOrder}/payments', [PosOrderController::class, 'addPayments'])
        ->middleware('idempotency');
    Route::post('orders/{posOrder}/cancel', [PosOrderController::class, 'cancel'])
        ->middleware('idempotency');
});
