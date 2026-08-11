<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InventoryManualMovementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'status' => $this->status,
            'product' => [
                'id' => $this->product?->id,
                'name' => $this->product?->name,
            ],
            'product_variant_id' => $this->product_variant_id,
            'product_variant' => $this->productVariant ? [
                'id' => $this->productVariant->id,
                'color' => $this->productVariant->color,
                'sku_variant' => $this->productVariant->sku_variant,
            ] : null,
            'quantity' => $this->quantity,
            'warehouse' => [
                'id' => $this->warehouse?->id,
                'name' => $this->warehouse?->name,
            ],
            'creator' => [
                'id' => $this->creator?->id,
                'name' => $this->creator?->name,
            ],
            'stock_movement_id' => $this->stock_movement_id,
            'approver' => [
                'id' => $this->approver?->id,
                'name' => $this->approver?->name,
            ],
            'approved_at' => $this->approved_at,
            'rejector' => [
                'id' => $this->rejector?->id,
                'name' => $this->rejector?->name,
            ],
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at,
        ];
    }
}
