<?php

use App\Modules\InventoryTransfers\Controllers\InventoryTransferController;
use Illuminate\Support\Facades\Route;

Route::post('inventory-transfers/{inventoryTransfer}/prepare', [InventoryTransferController::class, 'prepare']);

Route::apiResource('inventory-transfers', InventoryTransferController::class)
    ->parameters(['inventory-transfers' => 'inventoryTransfer'])
    ->only(['index', 'store', 'show']);
