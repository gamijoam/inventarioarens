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
}
