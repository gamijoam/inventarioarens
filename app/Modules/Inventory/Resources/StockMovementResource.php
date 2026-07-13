<?php

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeCosts = (bool) ($request->user()?->can('finance.costs.view') ?? false);

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'warehouse_id' => $this->warehouse_id,
            'product_id' => $this->product_id,
            'type' => $this->type,
            'quantity' => (float) $this->quantity,
            'unit_cost' => $canSeeCosts && $this->unit_cost !== null
                ? (float) $this->unit_cost
                : null,
            'reason' => $this->reason,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
