<?php

use App\Modules\Tenancy\Controllers\CrossTenantUserController;
use App\Modules\Tenancy\Controllers\GroupController;
use App\Modules\Tenancy\Controllers\MasterController;
use App\Modules\Tenancy\Controllers\PlatformAdminController;
use App\Modules\Tenancy\Controllers\TenantController;
use App\Modules\Tenancy\Middleware\EnsureGroupOwner;
use App\Modules\Tenancy\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.auth', 'tenant'])->group(function (): void {
    Route::get('tenants', [TenantController::class, 'index']);
    Route::post('tenants', [TenantController::class, 'store']);
    Route::get('tenants/{tenant}', [TenantController::class, 'show']);
    Route::patch('tenants/{tenant}', [TenantController::class, 'update']);
    Route::delete('tenants/{tenant}', [TenantController::class, 'destroy']);

    Route::get('tenants/{tenant}/users', [CrossTenantUserController::class, 'index']);
    Route::post('tenants/{tenant}/users', [CrossTenantUserController::class, 'store']);
    Route::delete('tenants/{tenant}/users/{user}', [CrossTenantUserController::class, 'destroy']);
});

Route::middleware(['api.auth', EnsurePlatformAdmin::class])
    ->prefix('master')
    ->group(function (): void {
        Route::get('stats', [MasterController::class, 'stats']);

        Route::get('groups', [MasterController::class, 'listGroups']);
        Route::post('groups', [MasterController::class, 'storeGroup']);
        Route::get('groups/{group}', [MasterController::class, 'showGroup']);
        Route::patch('groups/{group}', [MasterController::class, 'updateGroup']);
        Route::delete('groups/{group}', [MasterController::class, 'destroyGroup']);
        Route::get('groups/{group}/tenants', [MasterController::class, 'listGroupSpinoffs']);
        Route::post('groups/{group}/tenants', [MasterController::class, 'createGroupSpinoff']);

        Route::get('admins', [PlatformAdminController::class, 'index']);
        Route::post('admins', [PlatformAdminController::class, 'store']);
        Route::get('admins/{admin}', [PlatformAdminController::class, 'show']);
        Route::patch('admins/{admin}', [PlatformAdminController::class, 'update']);
        Route::delete('admins/{admin}', [PlatformAdminController::class, 'destroy']);
        Route::post('admins/{admin}/reset-password', [PlatformAdminController::class, 'resetPassword']);
    });

Route::middleware(['api.auth', EnsureGroupOwner::class])
    ->prefix('groups/{group}')
    ->group(function (): void {
        Route::get('tenants', [GroupController::class, 'listSpinoffs']);
        Route::post('tenants', [GroupController::class, 'storeSpinoff']);
    });