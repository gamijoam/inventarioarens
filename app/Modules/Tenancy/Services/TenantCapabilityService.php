<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantCapability;
use App\Support\Capabilities\BaseCapabilities;
use Illuminate\Support\Facades\DB;

class TenantCapabilityService
{
    public function enabledKeys(Tenant $tenant): array
    {
        $rows = $this->rowsFor($tenant);

        if ($rows->isEmpty()) {
            return BaseCapabilities::ALL;
        }

        $enabled = $rows
            ->filter(fn (TenantCapability $row): bool => (bool) $row->enabled)
            ->pluck('capability')
            ->all();

        return array_values(array_filter(
            BaseCapabilities::ALL,
            fn (string $capability): bool => in_array($capability, $enabled, true),
        ));
    }

    public function enabled(Tenant $tenant, string $capability): bool
    {
        if (! BaseCapabilities::isKnown($capability)) {
            return false;
        }

        $row = TenantCapability::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('capability', $capability)
            ->first();

        if ($row === null) {
            return $this->rowsFor($tenant)->isEmpty();
        }

        return (bool) $row->enabled;
    }

    public function initializeForNewTenant(Tenant $tenant): void
    {
        if ($this->rowsFor($tenant)->isNotEmpty()) {
            return;
        }

        $now = now();
        $rows = array_map(
            fn (string $capability): array => [
                'tenant_id' => $tenant->id,
                'capability' => $capability,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            BaseCapabilities::DEFAULT_NEW,
        );

        TenantCapability::withoutGlobalScopes()->insert($rows);
    }

    public function replaceEnabled(Tenant $tenant, array $requested): array
    {
        $enabled = array_values(array_unique(array_merge(BaseCapabilities::REQUIRED, $requested)));

        return DB::transaction(function () use ($tenant, $enabled): array {
            foreach (BaseCapabilities::ALL as $capability) {
                TenantCapability::withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'capability' => $capability,
                    ],
                    [
                        'enabled' => in_array($capability, $enabled, true),
                    ],
                );
            }

            return $this->enabledKeys($tenant);
        });
    }

    private function rowsFor(Tenant $tenant)
    {
        return TenantCapability::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderBy('capability')
            ->get();
    }
}
