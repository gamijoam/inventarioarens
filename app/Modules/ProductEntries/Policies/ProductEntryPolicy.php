<?php

namespace App\Modules\ProductEntries\Policies;

use App\Models\User;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Support\Tenancy\TenantManager;

class ProductEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'product_entries.view');
    }

    public function view(User $user, ProductEntry $entry): bool
    {
        return $this->ownsResource($entry)
            && $this->hasTenantPermission($user, 'product_entries.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'product_entries.create');
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

    private function ownsResource(ProductEntry $entry): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $entry->tenant_id === (int) $tenant->id;
    }
}
