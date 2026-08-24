<?php

namespace App\Modules\InventoryTransfers\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrepareInventoryTransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'prepared_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_transfer_item_id' => ['required', 'integer'],
            'items.*.prepared_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.prepared_product_unit_ids' => ['nullable', 'array'],
            'items.*.prepared_product_unit_ids.*' => ['integer'],
            'items.*.serial_units' => ['nullable', 'array'],
            'items.*.serial_units.*' => ['required', 'array'],
            'items.*.serial_units.*.serial_type' => ['required', 'string', 'in:imei,serial'],
            'items.*.serial_units.*.serial_number' => ['required', 'string', 'max:150'],
            'items.*.difference_reason' => ['nullable', 'string', 'max:255'],
            'items.*.difference_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
