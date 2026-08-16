<?php

namespace App\Modules\Promotions\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'code' => $this->code,
            'benefit_type' => $this->benefit_type,
            'price_currency' => $this->price_currency,
            'payment_currency' => $this->payment_currency,
            'scope' => $this->scope,
            'allows_combos' => $this->allows_combos,
            'price_usd' => $this->price_usd === null ? null : (float) $this->price_usd,
            'discount_percent' => $this->discount_percent === null ? null : (float) $this->discount_percent,
            'discount_amount_usd' => $this->discount_amount_usd === null ? null : (float) $this->discount_amount_usd,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'items' => PromotionItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
