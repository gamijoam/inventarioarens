<?php

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\TenantManager;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'sales.view');
    }

    public function view(User $user, Sale $sale): bool
    {
        return $this->ownsResource($sale)
            && $this->hasTenantPermission($user, 'sales.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'sales.create');
    }

    public function confirm(User $user, Sale $sale): bool
    {
        return $this->ownsResource($sale)
            && $this->hasTenantPermission($user, 'sales.create');
    }

    public function cancel(User $user, Sale $sale): bool
    {
        return $this->ownsResource($sale)
            && $this->hasTenantPermission($user, 'sales.cancel');
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

    private function ownsResource(Sale $sale): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $sale->tenant_id === (int) $tenantId;
    }
}
