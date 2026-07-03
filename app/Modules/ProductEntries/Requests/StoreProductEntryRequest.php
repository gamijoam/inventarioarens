<?php

namespace App\Modules\ProductEntries\Requests;

use App\Modules\Inventory\Models\ProductUnit;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductEntryRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'reason' => ['required', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'processed_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'items.*.serial_units' => ['nullable', 'array'],
            'items.*.serial_units.*.serial_type' => ['required_with:items.*.serial_units', Rule::in([
                ProductUnit::SERIAL_TYPE_IMEI,
                ProductUnit::SERIAL_TYPE_SERIAL,
            ])],
            'items.*.serial_units.*.serial_number' => ['required_with:items.*.serial_units', 'string', 'max:150'],
        ];
    }
}
