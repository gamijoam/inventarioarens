<?php

namespace App\Modules\AccountsPayable\Policies;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayablePaymentRequest;
use App\Support\Tenancy\TenantManager;

class AccountsPayablePaymentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'accounts_payable.payment_requests.view');
    }

    public function view(User $user, AccountsPayablePaymentRequest $request): bool
    {
        return $this->ownsResource($request)
            && $this->hasTenantPermission($user, 'accounts_payable.payment_requests.view');
    }

    public function approve(User $user, AccountsPayablePaymentRequest $request): bool
    {
        return $this->ownsResource($request)
            && $this->hasTenantPermission($user, 'accounts_payable.payment_requests.approve');
    }

    public function execute(User $user, AccountsPayablePaymentRequest $request): bool
    {
        return $this->ownsResource($request)
            && $this->hasTenantPermission($user, 'accounts_payable.payment_requests.execute');
    }

    public function cancel(User $user, AccountsPayablePaymentRequest $request): bool
    {
        return $this->ownsResource($request)
            && $this->hasTenantPermission($user, 'accounts_payable.payment_requests.cancel');
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

    private function ownsResource(AccountsPayablePaymentRequest $request): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $request->tenant_id === (int) $tenant->id;
    }
}
