<?php

use App\Modules\Dashboard\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('dashboard')->group(function (): void {
    Route::get('summary', [DashboardController::class, 'summary']);
});
