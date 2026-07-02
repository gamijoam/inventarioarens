<?php

use App\Modules\FinanceReports\Controllers\FinanceReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('finance-reports')->group(function (): void {
    Route::get('summary', [FinanceReportController::class, 'summary']);
    Route::get('receivables', [FinanceReportController::class, 'receivables']);
    Route::get('payables', [FinanceReportController::class, 'payables']);
});
