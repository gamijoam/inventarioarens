<?php

namespace App\Modules\PurchaseReturns\Resources;

use App\Modules\Products\Resources\ProductResource;
use App\Modules\Warehouses\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_return_id' => $this->purchase_return_id,
            'purchase_item_id' => $this->purchase_item_id,
            'warehouse_id' => $this->warehouse_id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'product_unit_ids' => $this->product_unit_ids,
            'stock_movement_id' => $this->stock_movement_id,
            'reason' => $this->reason,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
        ];
    }
}
