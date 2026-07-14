<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCountItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
            ] : null),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'code' => $this->location->code,
                'full_path' => $this->location->fullPath(),
            ] : null),
            'system_quantity' => (float) $this->system_quantity,
            'counted_quantity' => $this->counted_quantity === null ? null : (float) $this->counted_quantity,
            'variance' => $this->variance === null ? null : (float) $this->variance,
            'status' => $this->status,
            'notes' => $this->notes,
            'counted_at' => $this->counted_at?->toIso8601String(),
            'counted_by' => $this->counted_by,
        ];
    }
}
