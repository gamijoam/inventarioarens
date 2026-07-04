<?php

namespace App\Modules\Products\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPriceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'product_id' => $this->product_id,
            'price_list_id' => $this->price_list_id,
            'price_list' => $this->whenLoaded('priceList', fn () => [
                'id' => $this->priceList?->id,
                'name' => $this->priceList?->name,
                'code' => $this->priceList?->code,
                'is_default' => (bool) $this->priceList?->is_default,
                'is_active' => (bool) $this->priceList?->is_active,
            ]),
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type' => $this->whenLoaded('exchangeRateType', fn () => [
                'id' => $this->exchangeRateType?->id,
                'code' => $this->exchangeRateType?->code,
                'name' => $this->exchangeRateType?->name,
                'is_default' => (bool) $this->exchangeRateType?->is_default,
                'is_active' => (bool) $this->exchangeRateType?->is_active,
            ]),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
