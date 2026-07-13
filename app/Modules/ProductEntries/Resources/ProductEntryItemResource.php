<?php

namespace App\Modules\ProductEntries\Resources;

use App\Modules\Products\Resources\ProductResource;
use App\Modules\Warehouses\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductEntryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeCosts = (bool) ($request->user()?->can('finance.costs.view') ?? false);

        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'product_id' => $this->product_id,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'quantity' => $this->quantity,
            'unit_cost' => $this->when($canSeeCosts, $this->unit_cost),
            'stock_movement_id' => $this->stock_movement_id,
            'serial_units' => $this->serial_units,
        ];
    }
}
