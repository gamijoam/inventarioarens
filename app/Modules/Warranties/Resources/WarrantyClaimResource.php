<?php

namespace App\Modules\Warranties\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarrantyClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'sale_id' => $this->sale_id,
            'sale_item_id' => $this->sale_item_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn (): ?string => $this->product?->name),
            'product_unit_id' => $this->product_unit_id,
            'product_unit_serial' => $this->whenLoaded('productUnit', fn (): ?string => $this->productUnit?->serial_number),
            'status' => $this->status,
            'quantity' => (float) $this->quantity,
            'issue_description' => $this->issue_description,
            'received_notes' => $this->received_notes,
            'diagnosis' => $this->diagnosis,
            'resolution_type' => $this->resolution_type,
            'resolution_notes' => $this->resolution_notes,
            'received_by' => $this->received_by,
            'reviewed_by' => $this->reviewed_by,
            'delivered_by' => $this->delivered_by,
            'received_at' => $this->received_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'warranty_policy_name' => $this->whenLoaded('saleItem', fn (): ?string => $this->saleItem?->warranty_policy_name),
            'warranty_expires_at' => $this->whenLoaded('saleItem', fn (): ?string => $this->saleItem?->warranty_expires_at?->toISOString()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
