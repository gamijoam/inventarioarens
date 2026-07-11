<?php

use App\Modules\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('auth/tenants', [AuthController::class, 'tenants'])->middleware('throttle:auth');

Route::middleware('tenant')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
});

Route::middleware(['api.auth', 'tenant'])->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('auth/sessions', [AuthController::class, 'sessions']);
    Route::delete('auth/sessions/{tokenId}', [AuthController::class, 'revokeSession']);
});

Route::middleware('api.auth')->group(function (): void {
    Route::post('auth/switch-tenant', [AuthController::class, 'switchTenant']);
});
