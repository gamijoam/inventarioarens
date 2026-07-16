<?php

use App\Modules\InventoryCenter\Controllers\InventoryCenterController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory-center')->group(function (): void {
    Route::get('summary', [InventoryCenterController::class, 'summary']);
    Route::get('export', [InventoryCenterController::class, 'export']);
    Route::post('products/bulk-action', [InventoryCenterController::class, 'bulkAction']);
    Route::get('movements', [InventoryCenterController::class, 'movements']);

    Route::get('reorder-suggestions', [InventoryCenterController::class, 'reorderSuggestions']);
    Route::get('alerts-summary', [InventoryCenterController::class, 'alertsSummary']);

    Route::get('products/{product}', [InventoryCenterController::class, 'product']);
    Route::get('products/{product}/serials', [InventoryCenterController::class, 'productSerials']);
    Route::get('products/{product}/movements', [InventoryCenterController::class, 'productMovements']);
    Route::get('products/{product}/audits', [InventoryCenterController::class, 'productAudits']);
    Route::get('products/{product}/stock-by-warehouse', [InventoryCenterController::class, 'productStockByWarehouse']);
    Route::get('products/{product}/stock-status', [InventoryCenterController::class, 'productStockStatus']);
    Route::post('products/{product}/recalculate-price', [InventoryCenterController::class, 'recalculateProductPrice']);
    Route::patch('products/{product}/profit-margin', [InventoryCenterController::class, 'updateProductProfitMargin']);
});
