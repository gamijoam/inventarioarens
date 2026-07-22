<?php

namespace App\Modules\DataImport\Policies;

use App\Models\User;
use App\Modules\DataImport\Models\DataImport;

class DataImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('data_import.view');
    }

    public function view(User $user, DataImport $import): bool
    {
        if (! $user->can('data_import.view')) {
            return false;
        }

        return $import->tenant_id === $user->tenants()->first()?->id
            || $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->can('data_import.create');
    }

    public function execute(User $user, DataImport $import): bool
    {
        if (! $user->can('data_import.execute')) {
            return false;
        }

        if ($user->is_platform_admin) {
            return true;
        }

        return $user->tenants()
            ->where('tenants.id', $import->tenant_id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    public function delete(User $user, DataImport $import): bool
    {
        if (! $user->can('data_import.delete')) {
            return false;
        }

        if ($user->is_platform_admin) {
            return true;
        }

        return $user->tenants()
            ->where('tenants.id', $import->tenant_id)
            ->wherePivot('status', 'active')
            ->exists();
    }
}
