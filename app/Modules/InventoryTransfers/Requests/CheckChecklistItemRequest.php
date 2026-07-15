<?php

namespace App\Modules\InventoryTransfers\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marca 1 item del checklist (preparation o reception) como checked.
 * Permite registrar la cantidad confirmada y opcionalmente un IMEI/serial
 * especifico (para productos serializados).
 */
class CheckChecklistItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'checked_quantity' => ['nullable', 'numeric', 'gte:0'],
            'checked_product_unit_ids' => ['nullable', 'array'],
            'checked_product_unit_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
