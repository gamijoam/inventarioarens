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
            'guide_number' => $this->guide_number,
            'type' => $this->type,
            'validation_mode' => $this->validation_mode,
            'from_warehouse_id' => $this->from_warehouse_id,
            'to_warehouse_id' => $this->to_warehouse_id,
            'from_warehouse' => $this->whenLoaded('fromWarehouse'),
            'to_warehouse' => $this->whenLoaded('toWarehouse'),
            'guide' => $this->whenLoaded('guide'),
            'driver' => $this->whenLoaded('driver'),
            'checklists' => $this->whenLoaded('guide.checklists'),
            'status' => $this->status,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'processed_at' => $this->processed_at?->toISOString(),
            'requested_at' => $this->requested_at?->toISOString(),
            'prepared_at' => $this->prepared_at?->toISOString(),
            'dispatched_at' => $this->dispatched_at?->toISOString(),
            'received_at' => $this->received_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancelled_by' => $this->cancelled_by,
            'canceller' => $this->whenLoaded('canceller'),
            'resolution_status' => $this->resolution_status,
            'resolution_notes' => $this->resolution_notes,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'resolved_by' => $this->resolved_by,
            'resolver' => $this->whenLoaded('resolver'),
            'items' => InventoryTransferItemResource::collection($this->whenLoaded('items')),
            // Campos derivados (utiles para la UI: contadores, totales monetarios).
            'items_count' => $this->whenCounted('items'),
            'total_base_amount' => (float) ($this->total_base_amount ?? 0),
            'total_local_amount' => (float) ($this->total_local_amount ?? 0),
            'received_base_amount' => (float) ($this->received_base_amount ?? 0),
            'received_local_amount' => (float) ($this->received_local_amount ?? 0),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}