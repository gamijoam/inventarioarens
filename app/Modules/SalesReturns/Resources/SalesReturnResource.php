<?php

namespace App\Modules\SalesReturns\Resources;

use App\Modules\Sales\Resources\SaleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'sale' => SaleResource::make($this->whenLoaded('sale')),
            'items' => SalesReturnItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
