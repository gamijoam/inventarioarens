<?php

namespace App\Modules\Sales\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'sale_id' => $this->sale_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->whenLoaded('warehouse', fn (): ?string => $this->warehouse?->name),
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn (): ?string => $this->product?->name),
            'quantity' => (float) $this->quantity,
            'sale_currency' => $this->sale_currency,
            'unit_price' => (float) $this->unit_price,
            'total_amount' => (float) $this->total_amount,
            'base_unit_price' => (float) $this->base_unit_price,
            'base_total_amount' => (float) $this->base_total_amount,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            'stock_movement_id' => $this->stock_movement_id,
            'warranty_policy_id' => $this->warranty_policy_id,
            'warranty_policy_name' => $this->warranty_policy_name,
            'warranty_duration_days' => $this->warranty_duration_days,
            'warranty_coverage_type' => $this->warranty_coverage_type,
            'warranty_conditions' => $this->warranty_conditions,
            'warranty_starts_at' => $this->warranty_starts_at?->toISOString(),
            'warranty_expires_at' => $this->warranty_expires_at?->toISOString(),
        ];
    }
}
