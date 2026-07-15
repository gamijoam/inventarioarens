<?php

namespace App\Modules\InventoryTransfers\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DTO del transportista (driver) de un InventoryTransfer.
 */
class InventoryTransferDriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_transfer_id' => $this->inventory_transfer_id,
            'name' => $this->name,
            'document_number' => $this->document_number,
            'phone' => $this->phone,
            'vehicle_plate' => $this->vehicle_plate,
            'carrier_company' => $this->carrier_company,
            'picked_up_at' => $this->picked_up_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'signed_by_driver_at' => $this->signed_by_driver_at?->toIso8601String(),
            'signature_driver_url' => $this->signature_driver_url,
            'signed_by_receiver_at' => $this->signed_by_receiver_at?->toIso8601String(),
            'signature_receiver_url' => $this->signature_receiver_url,
            'notes' => $this->notes,
            'is_driver_signed' => (bool) $this->isDriverSigned(),
            'is_receiver_signed' => (bool) $this->isReceiverSigned(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
