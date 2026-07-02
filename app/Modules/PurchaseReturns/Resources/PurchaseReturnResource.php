<?php

namespace App\Modules\PurchaseReturns\Resources;

use App\Modules\Purchases\Resources\PurchaseOrderResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'purchase_order' => PurchaseOrderResource::make($this->whenLoaded('purchaseOrder')),
            'items' => PurchaseReturnItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
