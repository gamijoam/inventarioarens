<?php

use App\Modules\InventoryTransfers\Controllers\InventoryTransferController;
use Illuminate\Support\Facades\Route;

Route::apiResource('inventory-transfers', InventoryTransferController::class)
    ->parameters(['inventory-transfers' => 'inventoryTransfer'])
    ->only(['index', 'store', 'show']);
