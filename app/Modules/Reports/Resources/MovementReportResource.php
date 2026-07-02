<?php

namespace App\Modules\Reports\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovementReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'sku' => $this->product?->sku,
            'type' => $this->type,
            'quantity' => (float) $this->quantity,
            'unit_cost' => $this->unit_cost === null ? null : (float) $this->unit_cost,
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
