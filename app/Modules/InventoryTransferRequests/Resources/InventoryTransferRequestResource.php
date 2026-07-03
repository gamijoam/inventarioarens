<?php

namespace App\Modules\InventoryTransferRequests\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'document_number' => $this->document_number,
            'origin_tenant_id' => $this->origin_tenant_id,
            'destination_tenant_id' => $this->destination_tenant_id,
            'origin_tenant' => $this->whenLoaded('originTenant'),
            'destination_tenant' => $this->whenLoaded('destinationTenant'),
            'from_warehouse_id' => $this->from_warehouse_id,
            'destination_warehouse_id' => $this->destination_warehouse_id,
            'from_warehouse' => $this->whenLoaded('fromWarehouse'),
            'destination_warehouse' => $this->whenLoaded('destinationWarehouse'),
            'status' => $this->status,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'response_notes' => $this->response_notes,
            'requested_by' => $this->requested_by,
            'responded_by' => $this->responded_by,
            'requested_at' => $this->requested_at?->toISOString(),
            'responded_at' => $this->responded_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'items' => InventoryTransferRequestItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
