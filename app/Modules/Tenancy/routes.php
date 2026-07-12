<?php

use App\Modules\Tenancy\Controllers\CrossTenantUserController;
use App\Modules\Tenancy\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.auth')->group(function (): void {
    Route::get('tenants', [TenantController::class, 'index']);
    Route::post('tenants', [TenantController::class, 'store']);
    Route::get('tenants/{tenant}', [TenantController::class, 'show']);
    Route::patch('tenants/{tenant}', [TenantController::class, 'update']);
    Route::delete('tenants/{tenant}', [TenantController::class, 'destroy']);

    Route::get('tenants/{tenant}/users', [CrossTenantUserController::class, 'index']);
    Route::post('tenants/{tenant}/users', [CrossTenantUserController::class, 'store']);
    Route::delete('tenants/{tenant}/users/{user}', [CrossTenantUserController::class, 'destroy']);
});