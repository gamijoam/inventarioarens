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
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
