<?php

use App\Modules\InventoryCenter\Controllers\InventoryCenterController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory-center')->group(function (): void {
    Route::get('summary', [InventoryCenterController::class, 'summary']);
    Route::get('products/{product}', [InventoryCenterController::class, 'product']);
    Route::get('products/{product}/serials', [InventoryCenterController::class, 'productSerials']);
    Route::get('products/{product}/movements', [InventoryCenterController::class, 'productMovements']);
    Route::get('products/{product}/stock-by-warehouse', [InventoryCenterController::class, 'productStockByWarehouse']);
});
