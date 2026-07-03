<?php

use App\Modules\InventoryCenter\Controllers\InventoryCenterController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory-center')->group(function (): void {
    Route::get('summary', [InventoryCenterController::class, 'summary']);
    Route::get('products/{product}', [InventoryCenterController::class, 'product']);
});
