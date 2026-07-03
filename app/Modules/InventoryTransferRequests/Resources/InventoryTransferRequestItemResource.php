<?php

namespace App\Modules\InventoryTransferRequests\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'origin_product_id' => $this->origin_product_id,
            'origin_product' => $this->whenLoaded('originProduct'),
            'destination_product_id' => $this->destination_product_id,
            'destination_product' => $this->whenLoaded('destinationProduct'),
            'quantity' => (float) $this->quantity,
            'product_unit_ids' => $this->product_unit_ids,
            'serial_units' => $this->serial_units,
            'out_stock_movement_id' => $this->out_stock_movement_id,
            'in_stock_movement_id' => $this->in_stock_movement_id,
        ];
    }
}
