<?php

use App\Modules\AdminPortal\Controllers\AdminDashboardController;
use App\Modules\AdminPortal\Controllers\AdminOperationalReportController;
use App\Modules\AdminPortal\Controllers\AdminPosSalesController;
use App\Modules\AdminPortal\Controllers\AdminTransfersController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin-portal')->group(function (): void {
    Route::get('dashboard', [AdminDashboardController::class, 'show']);
    Route::get('operational-reports', [AdminOperationalReportController::class, 'show']);
    Route::get('pos-sales', [AdminPosSalesController::class, 'index']);
    Route::get('pos-sales/{posOrder}', [AdminPosSalesController::class, 'show']);
    Route::get('transfers', [AdminTransfersController::class, 'index']);
    Route::get('transfers/summary', [AdminTransfersController::class, 'summary']);
    Route::get('transfers/{inventoryTransfer}', [AdminTransfersController::class, 'show']);
    Route::post('transfers/{inventoryTransfer}/prepare', [AdminTransfersController::class, 'prepare']);
    Route::post('transfers/{inventoryTransfer}/dispatch', [AdminTransfersController::class, 'dispatch']);
    Route::post('transfers/{inventoryTransfer}/receive', [AdminTransfersController::class, 'receive']);
    Route::post('transfers/{inventoryTransfer}/cancel', [AdminTransfersController::class, 'cancel']);
    Route::post('transfers/{inventoryTransfer}/resolve-differences', [AdminTransfersController::class, 'resolveDifferences']);
});
