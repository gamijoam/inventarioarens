<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\Services\CompanySettings;

class FiscalIdentityService
{
    public const TAX_CONDITIONS = [
        'ordinary',
        'formal',
        'special',
        'exempt',
        'non_taxpayer',
    ];

    private const TENANT_FIELD_MAP = [
        'legal_name' => 'razon_social',
        'tax_id' => 'rif',
        'fiscal_address' => 'domicilio_fiscal',
        'city' => 'ciudad',
        'state' => 'estado',
        'phone' => 'telefono',
        'email' => 'correo',
    ];

    public function __construct(private readonly SyncCatalogOutboxService $syncCatalog) {}

    public function identity(Tenant $tenant): array
    {
        return [
            'tenant' => $tenant,
            'company' => CompanySettings::getForTenant($tenant),
            'branches' => Branch::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('name')
                ->get(),
        ];
    }

    public function branch(Tenant $tenant, int $branchId): Branch
    {
        return Branch::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($branchId)
            ->firstOrFail();
    }

    public function updateTenant(Tenant $tenant, array $data): array
    {
        $setting = $tenant->setting
            ?: TenantSetting::firstOrCreate(['tenant_id' => $tenant->id]);
        $company = [];

        foreach (self::TENANT_FIELD_MAP as $input => $stored) {
            if (array_key_exists($input, $data)) {
                $company[$stored] = $data[$input];
            }
        }

        if (array_key_exists('tax_condition', $data)) {
            $company['tax_condition'] = $data['tax_condition'];
        }

        $settings = array_replace_recursive(
            $setting->settings ?? [],
            ['company' => $company],
        );
        $setting->update(['settings' => $settings]);
        $this->syncCatalog->tenantSettingsUpdated($tenant, $setting->fresh());

        return $this->identity($tenant);
    }

    public function updateBranch(Tenant $tenant, int $branchId, array $data): Branch
    {
        $branch = $this->branch($tenant, $branchId);
        $branch->update([
            'fiscal_address' => $this->valueOrCurrent($data, 'fiscal_address', $branch->fiscal_address),
            'fiscal_city' => $this->valueOrCurrent($data, 'city', $branch->fiscal_city),
            'fiscal_state' => $this->valueOrCurrent($data, 'state', $branch->fiscal_state),
            'fiscal_phone' => $this->valueOrCurrent($data, 'phone', $branch->fiscal_phone),
            'fiscal_email' => $this->valueOrCurrent($data, 'email', $branch->fiscal_email),
            'tax_condition' => $this->valueOrCurrent($data, 'tax_condition', $branch->tax_condition),
        ]);

        return $branch->refresh();
    }

    private function valueOrCurrent(array $data, string $key, mixed $current): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : $current;
    }
}
