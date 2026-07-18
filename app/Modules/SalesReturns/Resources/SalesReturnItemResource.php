<?php

namespace App\Modules\SalesReturns\Resources;

use App\Modules\Products\Resources\ProductResource;
use App\Modules\Warehouses\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_return_id' => $this->sales_return_id,
            'sale_item_id' => $this->sale_item_id,
            'warehouse_id' => $this->warehouse_id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'refundable_base_amount' => $this->refundableBaseAmount(),
            'product_unit_ids' => $this->product_unit_ids,
            'stock_movement_id' => $this->stock_movement_id,
            'condition' => $this->condition,
            'reason' => $this->reason,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
        ];
    }

    private function refundableBaseAmount(): ?float
    {
        if (! $this->relationLoaded('saleItem') || ! $this->saleItem || (float) $this->saleItem->quantity <= 0.0) {
            return null;
        }

        return round(((float) $this->saleItem->base_total_amount / (float) $this->saleItem->quantity) * (float) $this->quantity, 4);
    }
}
