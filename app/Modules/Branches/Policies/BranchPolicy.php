<?php

namespace App\Modules\Branches\Policies;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Support\Tenancy\TenantManager;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'branches.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->ownsResource($branch)
            && $this->hasTenantPermission($user, 'branches.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'branches.create');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->ownsResource($branch)
            && $this->hasTenantPermission($user, 'branches.update');
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->ownsResource($branch)
            && $this->hasTenantPermission($user, 'branches.delete');
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

    private function ownsResource(Branch $branch): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && (int) $branch->tenant_id === (int) $tenantId;
    }
}
