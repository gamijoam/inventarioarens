<?php

namespace App\Modules\Currency\Policies;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Support\Tenancy\TenantManager;

class ExchangeRateTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'currency.view');
    }

    public function view(User $user, ExchangeRateType $type): bool
    {
        return $this->ownsResource($type)
            && $this->hasTenantPermission($user, 'currency.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'currency.manage');
    }

    public function update(User $user, ExchangeRateType $type): bool
    {
        return $this->ownsResource($type)
            && $this->hasTenantPermission($user, 'currency.manage');
    }

    public function delete(User $user, ExchangeRateType $type): bool
    {
        return $this->ownsResource($type)
            && $this->hasTenantPermission($user, 'currency.manage');
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

    private function ownsResource(ExchangeRateType $type): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $type->tenant_id === (int) $tenantId;
    }
}
