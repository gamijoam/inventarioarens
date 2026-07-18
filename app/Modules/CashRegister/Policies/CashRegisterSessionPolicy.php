<?php

namespace App\Modules\CashRegister\Policies;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Support\Tenancy\TenantManager;

class CashRegisterSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'cash_register.view');
    }

    public function view(User $user, CashRegisterSession $session): bool
    {
        return $this->ownsResource($session)
            && $this->hasTenantPermission($user, 'cash_register.view');
    }

    public function open(User $user): bool
    {
        return $this->hasTenantPermission($user, 'cash_register.open');
    }

    public function move(User $user, CashRegisterSession $session): bool
    {
        return $this->ownsResource($session)
            && $this->hasTenantPermission($user, 'cash_register.move');
    }

    public function close(User $user, CashRegisterSession $session): bool
    {
        return $this->ownsResource($session)
            && $this->hasTenantPermission($user, 'cash_register.close')
            && (
                (int) $session->cashier_id === (int) $user->id
                || $this->isCashSupervisor($user)
            );
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

    private function isCashSupervisor(User $user): bool
    {
        return $user->hasAnyRole(['Owner', 'Administrador', 'Gerente']);
    }

    private function ownsResource(CashRegisterSession $session): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $session->tenant_id === (int) $tenantId;
    }
}
