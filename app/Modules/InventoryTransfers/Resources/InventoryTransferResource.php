<?php

namespace App\Modules\InventoryTransfers\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'document_number' => $this->document_number,
            'type' => $this->type,
            'from_warehouse_id' => $this->from_warehouse_id,
            'to_warehouse_id' => $this->to_warehouse_id,
            'from_warehouse' => $this->whenLoaded('fromWarehouse'),
            'to_warehouse' => $this->whenLoaded('toWarehouse'),
            'status' => $this->status,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'processed_at' => $this->processed_at?->toISOString(),
            'items' => InventoryTransferItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
