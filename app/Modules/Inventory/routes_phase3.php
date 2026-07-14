<?php

use App\Modules\Inventory\Controllers\AlertHistoryController;
use App\Modules\Inventory\Controllers\StockCountController;
use App\Modules\Warehouses\Controllers\WarehouseLocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('warehouses/{warehouse}/locations')->group(function (): void {
    Route::get('/', [WarehouseLocationController::class, 'index']);
    Route::post('/', [WarehouseLocationController::class, 'store']);
    Route::get('{location}', [WarehouseLocationController::class, 'show']);
    Route::patch('{location}', [WarehouseLocationController::class, 'update']);
    Route::put('{location}', [WarehouseLocationController::class, 'update']);
    Route::delete('{location}', [WarehouseLocationController::class, 'destroy']);
});

Route::prefix('stock-counts')->group(function (): void {
    Route::get('/', [StockCountController::class, 'index']);
    Route::post('/', [StockCountController::class, 'store']);
    Route::get('{stockCount}', [StockCountController::class, 'show']);
    Route::patch('{stockCount}', [StockCountController::class, 'update']);
    Route::delete('{stockCount}', [StockCountController::class, 'destroy']);
    Route::post('{stockCount}/snapshot', [StockCountController::class, 'snapshot']);
    Route::post('{stockCount}/start', [StockCountController::class, 'start']);
    Route::post('{stockCount}/capture', [StockCountController::class, 'capture']);
    Route::post('{stockCount}/complete', [StockCountController::class, 'complete']);
});

Route::prefix('alert-history')->group(function (): void {
    Route::get('/', [AlertHistoryController::class, 'index']);
    Route::get('{alert}', [AlertHistoryController::class, 'show']);
    Route::post('{alert}/dismiss', [AlertHistoryController::class, 'dismiss']);
});
