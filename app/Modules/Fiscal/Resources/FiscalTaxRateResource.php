<?php

namespace App\Modules\Fiscal\Resources;

use App\Modules\Fiscal\Models\FiscalTaxRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalTaxRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FiscalTaxRate $taxRate */
        $taxRate = $this->resource;

        return [
            'id' => $taxRate->id,
            'tenant_id' => $taxRate->tenant_id,
            'code' => $taxRate->code,
            'name' => $taxRate->name,
            'rate' => (float) $taxRate->rate,
            'category' => $taxRate->category,
            'is_active' => (bool) $taxRate->is_active,
            'created_at' => $taxRate->created_at?->toISOString(),
            'updated_at' => $taxRate->updated_at?->toISOString(),
        ];
    }
}
