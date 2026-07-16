<?php

use App\Modules\Tenancy\Controllers\CrossTenantUserController;
use App\Modules\Tenancy\Controllers\GroupController;
use App\Modules\Tenancy\Controllers\MasterController;
use App\Modules\Tenancy\Controllers\PlatformAdminController;
use App\Modules\Tenancy\Controllers\TenantController;
use App\Modules\Tenancy\Controllers\TenantGroupController;
use App\Modules\Tenancy\Middleware\EnsureGroupOwner;
use App\Modules\Tenancy\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

/**
 * Rutas de tenant CRUD tradicional (spinoffs dentro de un grupo).
 */
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

/**
 * Tenant Groups: rutas para que un user autenticado (no necesariamente
 * platform admin) pueda crear SU PROPIO grupo + su primera empresa, y
 * luego administrar empresas hijas de ese grupo.
 *
 * Politica de acceso por endpoint:
 *  - POST /api/tenant-groups                   — cualquier user autenticado.
 *  - GET  /api/tenant-groups                   — solo Owners estrictos (rol 'Owner' + attach activo).
 *  - GET  /api/tenant-groups/{group}/spinoffs  — cualquier miembro activo del grupo.
 *  - POST /api/tenant-groups/{group}/tenants   — solo Owners estrictos del grupo.
 *
 * Estas rutas NO requieren tenant context (X-Tenant) porque el grupo
 * es el contexto y se pasa por la URL.
 */
Route::middleware(['api.auth'])->group(function (): void {
    Route::post('tenant-groups', [TenantGroupController::class, 'store']);
    Route::get('tenant-groups', [TenantGroupController::class, 'index']);

    // GET spinoffs: cualquier miembro activo (lectura). Verificacion inline.
    Route::get('tenant-groups/{group}/spinoffs', [TenantGroupController::class, 'spinoffs']);
});

// POST spinoffs + GET/POST users del grupo: solo Owners estrictos
// (todos los endpoints usan el mismo grupo de middleware).
Route::middleware(['api.auth', EnsureGroupOwner::class])
    ->prefix('tenant-groups/{group}')
    ->group(function (): void {
        Route::post('tenants', [TenantGroupController::class, 'createSpinoff']);
        Route::get('users', [TenantGroupController::class, 'users']);
        Route::post('users', [TenantGroupController::class, 'attachUser']);
    });

/**
 * Platform Admin (SaaS Master): crear grupos y asignar spinoffs a nivel
 * global. Solo accesible para `is_platform_admin = true`.
 */
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

/**
 * Legacy: rutas /api/groups/{group}/tenants (EnsureGroupOwner).
 * Mantienen la semantica estricta (Owner) para back-compat con codigo
 * que ya estuviera usandolas.
 */
Route::middleware(['api.auth', EnsureGroupOwner::class])
    ->prefix('groups/{group}')
    ->group(function (): void {
        Route::get('tenants', [GroupController::class, 'listSpinoffs']);
        Route::post('tenants', [GroupController::class, 'storeSpinoff']);
    });