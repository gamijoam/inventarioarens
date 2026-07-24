<?php

namespace App\Support\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;

class TenantManager
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new TenantNotResolvedException();
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function sharedTenantId(): ?int
    {
        $tenant = $this->current();

        if (! $tenant) {
            return null;
        }

        return $tenant->isGroup() || $tenant->parent_id === null
            ? $tenant->id
            : (int) $tenant->parent_id;
    }

    public function sharedTenantIds(): array
    {
        $tenant = $this->current();

        if (! $tenant) {
            return [];
        }

        if ($tenant->isGroup() || $tenant->parent_id === null) {
            return [$tenant->id];
        }

        return [(int) $tenant->id, (int) $tenant->parent_id];
    }

    public function sharedTenant(): ?Tenant
    {
        $tenant = $this->current();

        if (! $tenant) {
            return null;
        }

        if ($tenant->isGroup() || $tenant->parent_id === null) {
            return $tenant;
        }

        return $tenant->parent()->first() ?? $tenant;
    }
}
