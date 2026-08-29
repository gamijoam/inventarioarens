<?php

namespace App\Modules\Workshop\Resources;

use App\Modules\Workshop\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceOrder
 */
class ServiceOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'order_number' => $this->order_number,
            'type' => $this->type,
            'warranty_claim_id' => $this->warranty_claim_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'device_description' => $this->device_description,
            'issue_description' => $this->issue_description,
            'diagnosis' => $this->diagnosis,
            'status' => $this->status,
            'priority' => $this->priority,
            'resolution' => $this->resolution,
            'technician_id' => $this->technician_id,
            'technician' => $this->whenLoaded('technician', fn () => $this->technician ? [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
            ] : null),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ] : null),
            'labor_base_amount' => $this->labor_base_amount,
            'labor_local_amount' => $this->labor_local_amount,
            'parts_base_amount' => $this->parts_base_amount,
            'parts_local_amount' => $this->parts_local_amount,
            'total_base_amount' => $this->total_base_amount,
            'total_local_amount' => $this->total_local_amount,
            'notes' => $this->notes,
            'parts' => ServiceOrderPartResource::collection($this->whenLoaded('parts')),
            'created_by' => $this->created_by,
            'received_at' => $this->received_at?->toISOString(),
            'technician_assigned_at' => $this->technician_assigned_at?->toISOString(),
            'diagnosed_at' => $this->diagnosed_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
