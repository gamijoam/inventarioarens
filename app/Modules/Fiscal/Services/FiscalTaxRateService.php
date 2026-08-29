<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Fiscal\Models\FiscalTaxRate;
use App\Modules\Tenancy\Models\Tenant;

class FiscalTaxRateService
{
    public function list(Tenant $tenant): mixed
    {
        return FiscalTaxRate::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('code')
            ->get();
    }

    public function create(array $data): FiscalTaxRate
    {
        return FiscalTaxRate::create($data)->refresh();
    }

    public function find(Tenant $tenant, int $taxRateId): FiscalTaxRate
    {
        return FiscalTaxRate::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($taxRateId)
            ->firstOrFail();
    }

    public function update(FiscalTaxRate $taxRate, array $data): FiscalTaxRate
    {
        $taxRate->update($data);

        return $taxRate->refresh();
    }
}
