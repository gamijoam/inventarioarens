<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;

class InventoryPolicy
{
    public function view(User $user): bool
    {
        return $this->hasTenantPermission($user, 'inventory.view');
    }

    public function receive(User $user, Warehouse $warehouse, Product $product): bool
    {
        return $this->owns($warehouse)
            && $this->owns($product)
            && $this->hasTenantPermission($user, 'purchases.create');
    }

    public function sale(User $user, Warehouse $warehouse, Product $product): bool
    {
        return $this->owns($warehouse)
            && $this->owns($product)
            && $this->hasTenantPermission($user, 'sales.create');
    }

    public function adjust(User $user, Warehouse $warehouse, Product $product): bool
    {
        return $this->owns($warehouse)
            && $this->owns($product)
            && $this->hasTenantPermission($user, 'inventory.adjust');
    }

    public function transfer(User $user, Warehouse $fromWarehouse, Warehouse $toWarehouse, Product $product): bool
    {
        return $this->owns($fromWarehouse)
            && $this->owns($toWarehouse)
            && $this->owns($product)
            && $this->hasTenantPermission($user, 'inventory.transfer');
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

    private function owns(object $model): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $model->tenant_id === (int) $tenantId;
    }
}
