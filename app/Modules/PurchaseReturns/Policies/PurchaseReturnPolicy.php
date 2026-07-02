<?php

namespace App\Modules\PurchaseReturns\Policies;

use App\Models\User;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Support\Tenancy\TenantManager;

class PurchaseReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'purchase_returns.view');
    }

    public function view(User $user, PurchaseReturn $purchaseReturn): bool
    {
        return $this->ownsResource($purchaseReturn)
            && $this->hasTenantPermission($user, 'purchase_returns.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'purchase_returns.create');
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

    private function ownsResource(PurchaseReturn $purchaseReturn): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $purchaseReturn->tenant_id === (int) $tenant->id;
    }
}
