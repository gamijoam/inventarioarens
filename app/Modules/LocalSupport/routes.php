<?php

use App\Modules\LocalSupport\Controllers\LocalTechnicalConsoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('local-support')->middleware('throttle:auth')->group(function (): void {
    Route::get('status', [LocalTechnicalConsoleController::class, 'status']);
    Route::post('server-mode', [LocalTechnicalConsoleController::class, 'serverMode']);
    Route::post('connect', [LocalTechnicalConsoleController::class, 'connect']);
    Route::post('tenants/{tenant}/sync', [LocalTechnicalConsoleController::class, 'sync']);
    Route::post('tenants/{tenant}/worker', [LocalTechnicalConsoleController::class, 'worker']);
    Route::post('tenants/{tenant}/retry-failed', [LocalTechnicalConsoleController::class, 'retry']);
});
