<?php

namespace App\Modules\Quotations\Policies;

use App\Models\User;
use App\Modules\Quotations\Models\Quotation;
use App\Support\Tenancy\TenantManager;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'quotations.view');
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $this->ownsResource($quotation)
            && $this->hasTenantPermission($user, 'quotations.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'quotations.create');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $this->ownsResource($quotation)
            && $this->hasTenantPermission($user, 'quotations.update');
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return $this->ownsResource($quotation)
            && $this->hasTenantPermission($user, 'quotations.delete');
    }

    public function convert(User $user, Quotation $quotation): bool
    {
        return $this->ownsResource($quotation)
            && $this->hasTenantPermission($user, 'quotations.convert');
    }

    private function ownsResource(Quotation $quotation): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant !== null && (int) $quotation->tenant_id === (int) $tenant->id;
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
}
