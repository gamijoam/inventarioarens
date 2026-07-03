<?php

use App\Modules\AccessControl\Controllers\PermissionCatalogController;
use App\Modules\AccessControl\Controllers\RoleController;
use App\Modules\AccessControl\Controllers\TenantUserController;
use Illuminate\Support\Facades\Route;

Route::get('permissions', PermissionCatalogController::class);

Route::apiResource('roles', RoleController::class)
    ->only(['index', 'store', 'show', 'update', 'destroy']);
Route::patch('roles/{role}/permissions', [RoleController::class, 'permissions']);

Route::apiResource('users', TenantUserController::class)
    ->parameters(['users' => 'tenantUser'])
    ->only(['index', 'store', 'show', 'update']);
Route::patch('users/{tenantUser}/status', [TenantUserController::class, 'status']);
Route::patch('users/{tenantUser}/roles', [TenantUserController::class, 'roles']);
Route::get('users/{tenantUser}/permissions', [TenantUserController::class, 'permissions']);
