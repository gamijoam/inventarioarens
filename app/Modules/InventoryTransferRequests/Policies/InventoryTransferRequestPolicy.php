<?php

namespace App\Modules\InventoryTransferRequests\Policies;

use App\Models\User;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Support\Tenancy\TenantManager;

class InventoryTransferRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'inventory_transfer_requests.view');
    }

    public function view(User $user, InventoryTransferRequest $request): bool
    {
        return $this->ownsSide($request)
            && $this->hasTenantPermission($user, 'inventory_transfer_requests.view');
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'inventory_transfer_requests.create');
    }

    public function accept(User $user, InventoryTransferRequest $request): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant
            && (int) $request->destination_tenant_id === (int) $tenant->id
            && $this->hasTenantPermission($user, 'inventory_transfer_requests.respond');
    }

    public function reject(User $user, InventoryTransferRequest $request): bool
    {
        return $this->accept($user, $request);
    }

    public function cancel(User $user, InventoryTransferRequest $request): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant
            && (int) $request->origin_tenant_id === (int) $tenant->id
            && $this->hasTenantPermission($user, 'inventory_transfer_requests.cancel');
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

    private function ownsSide(InventoryTransferRequest $request): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant
            && (
                (int) $request->origin_tenant_id === (int) $tenant->id
                || (int) $request->destination_tenant_id === (int) $tenant->id
            );
    }
}
