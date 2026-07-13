<?php

use App\Modules\AccessControl\Controllers\PermissionCatalogController;
use App\Modules\AccessControl\Controllers\RoleController;
use App\Modules\AccessControl\Controllers\TenantUserController;
use App\Modules\AccessControl\Controllers\UserOverrideController;
use Illuminate\Support\Facades\Route;

Route::get('permissions', PermissionCatalogController::class);
Route::get('permission-catalog', [PermissionCatalogController::class, 'catalog']);

Route::apiResource('roles', RoleController::class)
    ->only(['index', 'store', 'show', 'update', 'destroy']);
Route::patch('roles/{role}/permissions', [RoleController::class, 'permissions']);
Route::post('roles/{role}/duplicate', [RoleController::class, 'duplicate']);
Route::get('roles/{role}/preview', [RoleController::class, 'preview']);

Route::apiResource('users', TenantUserController::class)
    ->parameters(['users' => 'tenantUser'])
    ->only(['index', 'store', 'show', 'update']);
Route::patch('users/{tenantUser}/status', [TenantUserController::class, 'status']);
Route::patch('users/{tenantUser}/roles', [TenantUserController::class, 'roles']);
Route::get('users/{tenantUser}/permissions', [TenantUserController::class, 'permissions']);

Route::get('tenants/{tenant}/users/{user}/overrides', [UserOverrideController::class, 'index']);
Route::put('tenants/{tenant}/users/{user}/overrides', [UserOverrideController::class, 'update']);
Route::delete('tenants/{tenant}/users/{user}/overrides/{permission}', [UserOverrideController::class, 'destroy']);
Route::get('tenants/{tenant}/users/{user}/effective-permissions', [UserOverrideController::class, 'effectivePermissions']);
