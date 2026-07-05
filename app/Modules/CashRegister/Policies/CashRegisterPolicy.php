<?php

namespace App\Modules\CashRegister\Policies;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Support\Tenancy\TenantManager;

class CashRegisterPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'cash_register.view');
    }

    public function view(User $user, CashRegister $cashRegister): bool
    {
        return $this->ownsResource($cashRegister)
            && $this->hasTenantPermission($user, 'cash_register.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'cash_register.open');
    }

    public function update(User $user, CashRegister $cashRegister): bool
    {
        return $this->ownsResource($cashRegister)
            && $this->hasTenantPermission($user, 'cash_register.open');
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

    private function ownsResource(CashRegister $cashRegister): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $cashRegister->tenant_id === (int) $tenantId;
    }
}
