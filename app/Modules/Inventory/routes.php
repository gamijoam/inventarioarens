<?php

use App\Modules\Inventory\Controllers\InventoryManualMovementController;
use App\Modules\Inventory\Controllers\InventoryMovementController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory')->group(function (): void {
    Route::post('purchases', [InventoryMovementController::class, 'purchase']);
    Route::post('sales', [InventoryMovementController::class, 'sale']);
    Route::post('adjustments/in', [InventoryMovementController::class, 'adjustmentIn']);
    Route::post('adjustments/out', [InventoryMovementController::class, 'adjustmentOut']);
    Route::post('reservations', [InventoryMovementController::class, 'reserve']);
    Route::post('releases', [InventoryMovementController::class, 'release']);
    Route::post('damages', [InventoryMovementController::class, 'damage']);
    Route::post('transfers', [InventoryMovementController::class, 'transfer']);

    // Manual inventory movements
    Route::post('manual-movements', [InventoryManualMovementController::class, 'store'])
        ->middleware('idempotency');
    Route::get('manual-movements', [InventoryManualMovementController::class, 'index']);
    Route::get('manual-movements/{movement}', [InventoryManualMovementController::class, 'show']);
    Route::post('manual-movements/{movement}/approve', [InventoryManualMovementController::class, 'approve'])
        ->middleware('idempotency');
    Route::post('manual-movements/{movement}/reject', [InventoryManualMovementController::class, 'reject'])
        ->middleware('idempotency');
});
