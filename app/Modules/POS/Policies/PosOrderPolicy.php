<?php

namespace App\Modules\POS\Policies;

use App\Models\User;
use App\Modules\POS\Models\PosOrder;
use App\Support\Tenancy\TenantManager;

class PosOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'pos.view');
    }

    public function view(User $user, PosOrder $order): bool
    {
        return $this->ownsResource($order)
            && $this->hasTenantPermission($user, 'pos.view');
    }

    public function checkout(User $user): bool
    {
        return $this->hasTenantPermission($user, 'pos.checkout');
    }

    public function addPayment(User $user, PosOrder $order): bool
    {
        return $this->ownsResource($order)
            && $this->hasTenantPermission($user, 'pos.checkout');
    }

    public function cancel(User $user, PosOrder $order): bool
    {
        return $this->ownsResource($order)
            && $this->hasTenantPermission($user, 'pos.cancel');
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

    private function ownsResource(PosOrder $order): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $order->tenant_id === (int) $tenantId;
    }
}
