<?php

namespace App\Modules\ProductExits\Resources;

use App\Modules\Products\Resources\ProductResource;
use App\Modules\Warehouses\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductExitItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'product_id' => $this->product_id,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'quantity' => $this->quantity,
            'stock_movement_id' => $this->stock_movement_id,
            'product_unit_ids' => $this->product_unit_ids,
            'serial_units' => $this->serialUnits(),
        ];
    }

    private function serialUnits(): array
    {
        return collect($this->getAttribute('serial_units') ?? [])
            ->map(fn ($unit): array => [
                'id' => $unit->id,
                'serial_type' => $unit->serial_type,
                'serial_number' => $unit->serial_number,
                'status' => $unit->status,
                'warehouse_id' => $unit->warehouse_id,
            ])
            ->values()
            ->all();
    }
}
