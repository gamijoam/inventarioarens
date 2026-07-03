<?php

namespace App\Modules\Purchases\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'received_at' => ['nullable', 'date'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.serial_units' => ['sometimes', 'array'],
            'items.*.serial_units.*.serial_type' => ['required_with:items.*.serial_units', 'string', 'in:imei,serial'],
            'items.*.serial_units.*.serial_number' => ['required_with:items.*.serial_units', 'string', 'max:255'],
        ];
    }
}
