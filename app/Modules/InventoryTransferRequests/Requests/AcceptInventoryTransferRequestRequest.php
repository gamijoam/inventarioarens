<?php

namespace App\Modules\InventoryTransferRequests\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcceptInventoryTransferRequestRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'destination_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'response_notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.request_item_id' => ['required', 'integer'],
            'items.*.destination_product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.serial_units' => ['nullable', 'array'],
            'items.*.serial_units.*.serial_type' => ['required_with:items.*.serial_units', 'string', 'in:imei,serial'],
            'items.*.serial_units.*.serial_number' => ['required_with:items.*.serial_units', 'string', 'max:100'],
        ];
    }
}
