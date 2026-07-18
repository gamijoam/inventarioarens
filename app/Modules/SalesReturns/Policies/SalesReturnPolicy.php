<?php

namespace App\Modules\SalesReturns\Policies;

use App\Models\User;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Support\Tenancy\TenantManager;

class SalesReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'sales_returns.view');
    }

    public function view(User $user, SalesReturn $salesReturn): bool
    {
        return $this->ownsResource($salesReturn)
            && $this->hasTenantPermission($user, 'sales_returns.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'sales_returns.create');
    }

    public function review(User $user, SalesReturn $salesReturn): bool
    {
        return $this->ownsResource($salesReturn)
            && $this->hasTenantPermission($user, 'sales_returns.review');
    }

    public function process(User $user, SalesReturn $salesReturn): bool
    {
        return $this->ownsResource($salesReturn)
            && $this->hasTenantPermission($user, 'sales_returns.process');
    }

    public function refund(User $user, SalesReturn $salesReturn): bool
    {
        return $this->ownsResource($salesReturn)
            && $this->hasTenantPermission($user, 'sales_returns.refund');
    }

    public function cancel(User $user, SalesReturn $salesReturn): bool
    {
        return $this->ownsResource($salesReturn)
            && $this->hasTenantPermission($user, 'sales_returns.cancel');
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

    private function ownsResource(SalesReturn $salesReturn): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $salesReturn->tenant_id === (int) $tenant->id;
    }
}
