<?php

namespace App\Modules\Workshop\Policies;

use App\Models\User;
use App\Modules\Workshop\Models\ServiceOrder;
use App\Support\Tenancy\TenantManager;

class ServiceOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'service_orders.view');
    }

    public function view(User $user, ServiceOrder $order): bool
    {
        return $this->ownsResource($order)
            && $this->hasTenantPermission($user, 'service_orders.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'service_orders.create');
    }

    public function update(User $user, ServiceOrder $order): bool
    {
        return $this->ownsResource($order)
            && $this->hasTenantPermission($user, 'service_orders.update');
    }

    public function assignTechnician(User $user, ServiceOrder $order): bool
    {
        return $this->ownsResource($order)
            && $this->hasTenantPermission($user, 'service_orders.assign_technician');
    }

    public function close(User $user, ServiceOrder $order): bool
    {
        return $this->ownsResource($order)
            && $this->hasTenantPermission($user, 'service_orders.close');
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

    private function ownsResource(ServiceOrder $order): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $order->tenant_id === (int) $tenant->id;
    }
}
