<?php

namespace App\Modules\Customers\Policies;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Support\Tenancy\TenantManager;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->ownsResource($customer)
            && $this->hasTenantPermission($user, 'customers.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->ownsResource($customer)
            && $this->hasTenantPermission($user, 'customers.update');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->ownsResource($customer)
            && $this->hasTenantPermission($user, 'customers.delete');
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

    private function ownsResource(Customer $customer): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $customer->tenant_id === (int) $tenantId;
    }
}
