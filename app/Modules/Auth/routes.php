<?php

use App\Modules\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('auth/tenants', [AuthController::class, 'tenants']);

Route::middleware('tenant')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login']);
});

Route::middleware(['api.auth', 'tenant'])->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
});

Route::middleware('api.auth')->group(function (): void {
    Route::post('auth/switch-tenant', [AuthController::class, 'switchTenant']);
});
