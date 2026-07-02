<?php

namespace App\Modules\AccountsPayable\Policies;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Support\Tenancy\TenantManager;

class AccountsPayablePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'accounts_payable.view');
    }

    public function view(User $user, AccountsPayable $account): bool
    {
        return $this->ownsResource($account)
            && $this->hasTenantPermission($user, 'accounts_payable.view');
    }

    public function pay(User $user, AccountsPayable $account): bool
    {
        return $this->ownsResource($account)
            && $this->hasTenantPermission($user, 'accounts_payable.pay');
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

    private function ownsResource(AccountsPayable $account): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $account->tenant_id === (int) $tenant->id;
    }
}
