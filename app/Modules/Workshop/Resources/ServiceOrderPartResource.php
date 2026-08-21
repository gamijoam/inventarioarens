<?php

namespace App\Modules\Workshop\Resources;

use App\Modules\Workshop\Models\ServiceOrderPart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceOrderPart
 */
class ServiceOrderPartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_order_id' => $this->service_order_id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
            ] : null),
            'product_variant_id' => $this->product_variant_id,
            'warehouse_id' => $this->warehouse_id,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'unit_price' => $this->unit_price,
            'base_unit_price' => $this->base_unit_price,
            'base_unit_cost' => $this->base_unit_cost,
            'stock_movement_id' => $this->stock_movement_id,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
