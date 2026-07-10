<?php

use App\Modules\InventoryTransfers\Controllers\InventoryTransferController;
use Illuminate\Support\Facades\Route;

Route::post('inventory-transfers/{inventoryTransfer}/prepare', [InventoryTransferController::class, 'prepare']);
Route::post('inventory-transfers/{inventoryTransfer}/dispatch', [InventoryTransferController::class, 'dispatch']);
Route::post('inventory-transfers/{inventoryTransfer}/receive', [InventoryTransferController::class, 'receive']);
Route::post('inventory-transfers/{inventoryTransfer}/cancel', [InventoryTransferController::class, 'cancel']);
Route::post('inventory-transfers/{inventoryTransfer}/resolve-differences', [InventoryTransferController::class, 'resolveDifferences']);

Route::apiResource('inventory-transfers', InventoryTransferController::class)
    ->parameters(['inventory-transfers' => 'inventoryTransfer'])
    ->only(['index', 'store', 'show']);
