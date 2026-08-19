<?php

namespace App\Modules\Quotations\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => (int) $this->sequence,
            'document_number' => $this->document_number,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse'),
            'status' => $this->status,
            'valid_until' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
            'subtotal_base_amount' => (float) $this->subtotal_base_amount,
            'subtotal_local_amount' => (float) $this->subtotal_local_amount,
            'discount_base_amount' => (float) $this->discount_base_amount,
            'discount_local_amount' => (float) $this->discount_local_amount,
            'total_base_amount' => (float) $this->total_base_amount,
            'total_local_amount' => (float) $this->total_local_amount,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate === null ? null : (float) $this->exchange_rate,
            'issued_at' => $this->issued_at?->toISOString(),
            'converted_at' => $this->converted_at?->toISOString(),
            'converted_pos_order_id' => $this->converted_pos_order_id,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'items' => QuotationItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
