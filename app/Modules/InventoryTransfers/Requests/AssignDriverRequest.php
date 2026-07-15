<?php

namespace App\Modules\InventoryTransfers\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asigna o actualiza el transportista (driver) de un InventoryTransfer.
 * El transportista NO necesita user en el sistema; solo datos.
 */
class AssignDriverRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'document_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'carrier_company' => ['nullable', 'string', 'max:150'],
            'picked_up_at' => ['nullable', 'date'],
            'delivered_at' => ['nullable', 'date'],
            'signed_by_driver_at' => ['nullable', 'date'],
            'signature_driver_url' => ['nullable', 'string', 'max:500'],
            'signed_by_receiver_at' => ['nullable', 'date'],
            'signature_receiver_url' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
