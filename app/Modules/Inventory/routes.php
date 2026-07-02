<?php

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
});
