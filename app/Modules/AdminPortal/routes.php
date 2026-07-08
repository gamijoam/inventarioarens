<?php

use App\Modules\AdminPortal\Controllers\AdminDashboardController;
use App\Modules\AdminPortal\Controllers\AdminOperationalReportController;
use App\Modules\AdminPortal\Controllers\AdminPosSalesController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin-portal')->group(function (): void {
    Route::get('dashboard', [AdminDashboardController::class, 'show']);
    Route::get('operational-reports', [AdminOperationalReportController::class, 'show']);
    Route::get('pos-sales', [AdminPosSalesController::class, 'index']);
    Route::get('pos-sales/{posOrder}', [AdminPosSalesController::class, 'show']);
});
