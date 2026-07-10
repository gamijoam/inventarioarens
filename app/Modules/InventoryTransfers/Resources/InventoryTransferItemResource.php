<?php

namespace App\Modules\InventoryTransfers\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product'),
            'quantity' => (float) $this->quantity,
            'requested_quantity' => $this->requested_quantity === null ? null : (float) $this->requested_quantity,
            'prepared_quantity' => $this->prepared_quantity === null ? null : (float) $this->prepared_quantity,
            'received_quantity' => $this->received_quantity === null ? null : (float) $this->received_quantity,
            'difference_quantity' => (float) $this->difference_quantity,
            'difference_reason' => $this->difference_reason,
            'difference_notes' => $this->difference_notes,
            'out_stock_movement_id' => $this->out_stock_movement_id,
            'in_stock_movement_id' => $this->in_stock_movement_id,
            'product_unit_ids' => $this->product_unit_ids,
            'prepared_product_unit_ids' => $this->prepared_product_unit_ids,
            'received_product_unit_ids' => $this->received_product_unit_ids,
            'resolution_status' => $this->resolution_status,
            'resolution_notes' => $this->resolution_notes,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'resolved_by' => $this->resolved_by,
            'resolver' => $this->whenLoaded('resolver'),
        ];
    }
}
