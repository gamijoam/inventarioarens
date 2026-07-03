<?php

namespace App\Modules\FinancialAdjustments\Policies;

use App\Models\User;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Support\Tenancy\TenantManager;

class FinancialAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'financial_adjustments.view');
    }

    public function view(User $user, FinancialAdjustment $adjustment): bool
    {
        return $this->ownsResource($adjustment)
            && $this->hasTenantPermission($user, 'financial_adjustments.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'financial_adjustments.create');
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

    private function ownsResource(FinancialAdjustment $adjustment): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $adjustment->tenant_id === (int) $tenant->id;
    }
}
