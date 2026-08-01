<?php

namespace App\Modules\Products\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $warehouseId = $request->query('warehouse_id');

        $stockQuery = $this->stockBalances();
        if ($warehouseId !== null) {
            $stockQuery->where('warehouse_id', (int) $warehouseId);
        }

        $stockAvailable = (float) $stockQuery->sum('quantity_available');

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'color' => $this->color,
            'color_hex' => $this->color_hex,
            'sku_variant' => $this->sku_variant,
            'barcode_variant' => $this->barcode_variant,
            'price_override' => $this->price_override,
            'is_active' => (bool) $this->is_active,
            'position' => $this->position,
            'stock_available' => $stockAvailable,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
