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
            'out_stock_movement_id' => $this->out_stock_movement_id,
            'in_stock_movement_id' => $this->in_stock_movement_id,
            'product_unit_ids' => $this->product_unit_ids,
        ];
    }
}
