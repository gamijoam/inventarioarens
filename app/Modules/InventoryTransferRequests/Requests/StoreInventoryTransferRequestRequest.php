<?php

namespace App\Modules\InventoryTransferRequests\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryTransferRequestRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'destination_tenant_slug' => ['nullable', 'string', 'max:255', 'required_without:destination_user_email'],
            'destination_user_email' => ['nullable', 'email', 'max:255', 'required_without:destination_tenant_slug'],
            'from_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'reason' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.product_unit_ids' => ['nullable', 'array'],
            'items.*.product_unit_ids.*' => ['integer'],
            'items.*.serial_units' => ['nullable', 'array'],
            'items.*.serial_units.*.serial_type' => ['required_with:items.*.serial_units', 'string', 'in:imei,serial'],
            'items.*.serial_units.*.serial_number' => ['required_with:items.*.serial_units', 'string', 'max:100'],
        ];
    }
}
