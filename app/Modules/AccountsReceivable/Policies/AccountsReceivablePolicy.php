<?php

namespace App\Modules\AccountsReceivable\Policies;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Support\Tenancy\TenantManager;

class AccountsReceivablePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'accounts_receivable.view');
    }

    public function view(User $user, AccountsReceivable $account): bool
    {
        return $this->ownsResource($account)
            && $this->hasTenantPermission($user, 'accounts_receivable.view');
    }

    public function collect(User $user, AccountsReceivable $account): bool
    {
        return $this->ownsResource($account)
            && $this->hasTenantPermission($user, 'accounts_receivable.collect');
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

    private function ownsResource(AccountsReceivable $account): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $account->tenant_id === (int) $tenant->id;
    }
}
