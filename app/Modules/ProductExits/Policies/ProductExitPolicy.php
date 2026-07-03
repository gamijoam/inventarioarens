<?php

namespace App\Modules\ProductExits\Policies;

use App\Models\User;
use App\Modules\ProductExits\Models\ProductExit;
use App\Support\Tenancy\TenantManager;

class ProductExitPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'product_exits.view');
    }

    public function view(User $user, ProductExit $exit): bool
    {
        return $this->ownsResource($exit)
            && $this->hasTenantPermission($user, 'product_exits.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'product_exits.create');
    }

    private function hasTenantPermission(User $user, string $permission): bool
    {
        $tenant = app(TenantManager::class)->current();

        if (! $tenant || ! $user->belongsToTenant($tenant)) {
            return false;
        }

        setPermissionsTeamId($tenant->id);

        return $user->can($permission);
    }

    private function ownsResource(ProductExit $exit): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $exit->tenant_id === (int) $tenant->id;
    }
}
