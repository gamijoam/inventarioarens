<?php

namespace App\Modules\Purchases\Policies;

use App\Models\User;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Support\Tenancy\TenantManager;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'purchases.view');
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->ownsResource($purchaseOrder)
            && $this->hasTenantPermission($user, 'purchases.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'purchases.create');
    }

    public function receive(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->ownsResource($purchaseOrder)
            && $this->hasTenantPermission($user, 'purchases.approve');
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->ownsResource($purchaseOrder)
            && $this->hasTenantPermission($user, 'purchases.create');
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

    private function ownsResource(PurchaseOrder $purchaseOrder): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $purchaseOrder->tenant_id === (int) $tenant->id;
    }
}
