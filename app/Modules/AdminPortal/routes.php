<?php

use App\Modules\AdminPortal\Controllers\AdminDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin-portal')->group(function (): void {
    Route::get('dashboard', [AdminDashboardController::class, 'show']);
});
