<?php

namespace App\Modules\InventoryTransferRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuideItemsRequest extends FormRequest
{
    public function rules(): array
    {
        $prefix = $this->routeIs('inventory-transfer-requests.guide.receive') ? 'received' : 'prepared';

        return [
            'carrier_name' => ['nullable', 'string', 'max:150'],
            'carrier_document_number' => ['nullable', 'string', 'max:50'],
            'carrier_phone' => ['nullable', 'string', 'max:50'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'carrier_company' => ['nullable', 'string', 'max:150'],
            'carrier_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.request_item_id' => ['required', 'integer'],
            "items.*.{$prefix}_quantity" => ['required', 'numeric', 'min:0'],
            "items.*.{$prefix}_serial_units" => ['nullable', 'array'],
            "items.*.{$prefix}_serial_units.*.serial_type" => ['required_with:items.*.'.$prefix.'_serial_units', 'string', 'in:imei,serial'],
            "items.*.{$prefix}_serial_units.*.serial_number" => ['required_with:items.*.'.$prefix.'_serial_units', 'string', 'max:100'],
            'items.*.difference_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
