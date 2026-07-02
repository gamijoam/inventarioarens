<?php

namespace App\Modules\Warehouses\Policies;

use App\Models\User;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'warehouses.view');
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->ownsResource($warehouse)
            && $this->hasTenantPermission($user, 'warehouses.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'warehouses.create');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->ownsResource($warehouse)
            && $this->hasTenantPermission($user, 'warehouses.update');
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $this->ownsResource($warehouse)
            && $this->hasTenantPermission($user, 'warehouses.delete');
    }

    private function hasTenantPermission(User $user, string $permission): bool
    {
        $tenant = app(TenantManager::class)->current();

        if (! $tenant || ! $user->belongsToTenant($tenant)) {
            return false;
        }

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }

        return $user->hasPermissionTo($permission);
    }

    private function ownsResource(Warehouse $warehouse): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $warehouse->tenant_id === (int) $tenantId;
    }
}
