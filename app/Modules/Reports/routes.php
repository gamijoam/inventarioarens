<?php

use App\Modules\Reports\Controllers\InventoryReportController;
use App\Modules\Reports\Controllers\OperationalReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->group(function (): void {
    Route::get('catalog', [OperationalReportController::class, 'catalog']);
    Route::get('daily-operations', [OperationalReportController::class, 'dailyOperations']);
    Route::get('sales-detail', [OperationalReportController::class, 'salesDetail']);
    Route::get('cash-sessions', [OperationalReportController::class, 'cashSessions']);
    Route::get('payment-methods', [OperationalReportController::class, 'paymentMethods']);
    Route::get('stock', [InventoryReportController::class, 'stock']);
    Route::get('stock/low', [InventoryReportController::class, 'lowStock']);
    Route::get('movements', [InventoryReportController::class, 'movements']);
});
