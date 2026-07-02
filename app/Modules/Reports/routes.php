<?php

use App\Modules\Reports\Controllers\InventoryReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->group(function (): void {
    Route::get('stock', [InventoryReportController::class, 'stock']);
    Route::get('stock/low', [InventoryReportController::class, 'lowStock']);
    Route::get('movements', [InventoryReportController::class, 'movements']);
});
