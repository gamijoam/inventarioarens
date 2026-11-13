<?php

namespace App\Modules\Purchases\Resources;

use App\Modules\Products\Resources\ProductResource;
use App\Modules\Warehouses\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeCosts = (bool) ($request->user()?->can('finance.costs.view') ?? false);

        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'warehouse_id' => $this->warehouse_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'quantity' => $this->quantity,
            'received_quantity' => $this->received_quantity,
            'unit_cost' => $this->when($canSeeCosts, $this->unit_cost),
            'total_cost' => $this->when($canSeeCosts, $this->total_cost),
            'base_unit_cost' => $this->when($canSeeCosts, $this->base_unit_cost),
            'base_total_cost' => $this->when($canSeeCosts, $this->base_total_cost),
            'new_sale_price' => $this->new_sale_price === null ? null : (float) $this->new_sale_price,
            'serial_units' => $this->serial_units,
            'product_packaging_id' => $this->product_packaging_id,
            'packaging_quantity' => $this->packaging_quantity === null ? null : (float) $this->packaging_quantity,
            'packaging_cost' => $this->packaging_cost === null ? null : (float) $this->packaging_cost,
            'packaging' => $this->product_packaging_id === null ? null : [
                'id' => $this->product_packaging_id,
                'name' => $this->productPackaging?->name,
                'factor' => $this->productPackaging === null ? null : (float) $this->productPackaging->factor,
            ],
            'stock_movement_id' => $this->stock_movement_id,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'product_variant' => $this->whenLoaded('productVariant', fn () => [
                'id' => $this->productVariant->id,
                'color' => $this->productVariant->color,
                'color_hex' => $this->productVariant->color_hex,
                'sku_variant' => $this->productVariant->sku_variant,
                'barcode_variant' => $this->productVariant->barcode_variant,
            ]),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
        ];
    }
}
