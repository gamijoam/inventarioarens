<?php

namespace App\Modules\Products\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'tracking_type' => $this->tracking_type,
            'base_price' => $this->base_price === null ? null : (float) $this->base_price,
            'sale_currency' => $this->sale_currency,
            'sale_exchange_rate_type_id' => $this->sale_exchange_rate_type_id,
            'sale_exchange_rate_type' => $this->whenLoaded('saleExchangeRateType', fn () => [
                'id' => $this->saleExchangeRateType?->id,
                'code' => $this->saleExchangeRateType?->code,
                'name' => $this->saleExchangeRateType?->name,
                'is_default' => (bool) $this->saleExchangeRateType?->is_default,
                'is_active' => (bool) $this->saleExchangeRateType?->is_active,
            ]),
            'warranty_policy_id' => $this->warranty_policy_id,
            'warranty_policy' => $this->whenLoaded('warrantyPolicy', fn () => [
                'id' => $this->warrantyPolicy?->id,
                'name' => $this->warrantyPolicy?->name,
                'duration_days' => $this->warrantyPolicy?->duration_days,
                'coverage_type' => $this->warrantyPolicy?->coverage_type,
            ]),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
