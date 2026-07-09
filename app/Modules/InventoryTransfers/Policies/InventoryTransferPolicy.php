<?php

namespace App\Modules\InventoryTransfers\Policies;

use App\Models\User;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Support\Tenancy\TenantManager;

class InventoryTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'inventory_transfers.view');
    }

    public function view(User $user, InventoryTransfer $transfer): bool
    {
        return $this->ownsResource($transfer)
            && $this->hasTenantPermission($user, 'inventory_transfers.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'inventory_transfers.create');
    }

    public function prepare(User $user, InventoryTransfer $transfer): bool
    {
        return $this->ownsResource($transfer)
            && $this->hasTenantPermission($user, 'inventory_transfers.prepare');
    }

    public function dispatch(User $user, InventoryTransfer $transfer): bool
    {
        return $this->ownsResource($transfer)
            && $this->hasTenantPermission($user, 'inventory_transfers.dispatch');
    }

    public function receive(User $user, InventoryTransfer $transfer): bool
    {
        return $this->ownsResource($transfer)
            && $this->hasTenantPermission($user, 'inventory_transfers.receive');
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

    private function ownsResource(InventoryTransfer $transfer): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $transfer->tenant_id === (int) $tenant->id;
    }
}
