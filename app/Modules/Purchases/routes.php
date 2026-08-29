<?php

use App\Modules\Purchases\Controllers\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('purchases')->group(function (): void {
    Route::get('/', [PurchaseOrderController::class, 'index']);
    Route::post('/', [PurchaseOrderController::class, 'store'])->middleware('idempotency');
    Route::get('/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::patch('/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->middleware('idempotency');
    Route::patch('/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('idempotency');
});
