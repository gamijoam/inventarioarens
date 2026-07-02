<?php

namespace App\Modules\Products\Policies;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->ownsResource($product)
            && $this->hasTenantPermission($user, 'products.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->ownsResource($product)
            && $this->hasTenantPermission($user, 'products.update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->ownsResource($product)
            && $this->hasTenantPermission($user, 'products.delete');
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

    private function ownsResource(Product $product): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $product->tenant_id === (int) $tenantId;
    }
}
