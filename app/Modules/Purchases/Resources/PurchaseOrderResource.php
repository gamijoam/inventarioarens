<?php

namespace App\Modules\Purchases\Resources;

use App\Modules\Suppliers\Resources\SupplierResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'status' => $this->status,
            'document_number' => $this->document_number,
            'purchase_currency' => $this->purchase_currency,
            'exchange_rate_type_id' => $this->exchange_rate_type_id,
            'exchange_rate_type_code' => $this->exchange_rate_type_code,
            'exchange_rate' => $this->exchange_rate,
            'total_base_amount' => $this->total_base_amount,
            'total_local_amount' => $this->total_local_amount,
            'created_by' => $this->created_by,
            'received_at' => $this->received_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'items' => PurchaseItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
