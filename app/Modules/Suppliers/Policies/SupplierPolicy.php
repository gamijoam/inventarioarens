<?php

namespace App\Modules\Suppliers\Policies;

use App\Models\User;
use App\Modules\Suppliers\Models\Supplier;
use App\Support\Tenancy\TenantManager;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->ownsResource($supplier)
            && $this->hasTenantPermission($user, 'suppliers.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'suppliers.create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->ownsResource($supplier)
            && $this->hasTenantPermission($user, 'suppliers.update');
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->ownsResource($supplier)
            && $this->hasTenantPermission($user, 'suppliers.delete');
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

    private function ownsResource(Supplier $supplier): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $supplier->tenant_id === (int) $tenant->id;
    }
}
