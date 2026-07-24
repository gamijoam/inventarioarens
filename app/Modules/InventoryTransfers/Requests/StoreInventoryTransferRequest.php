<?php

namespace App\Modules\InventoryTransfers\Requests;

use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryTransferRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'type' => ['sometimes', Rule::in(InventoryTransfer::TYPES)],
            'validation_mode' => ['sometimes', Rule::in(InventoryTransfer::VALIDATION_MODES)],
            'from_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'to_warehouse_id' => [
                'required',
                'different:from_warehouse_id',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'processed_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->whereIn('tenant_id', $tenantIds)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.product_unit_ids' => ['nullable', 'array'],
            'items.*.product_unit_ids.*' => ['integer'],
            // Fase T2 (Fase 0 - fix IMEI flow): el frontend envia serial_units
            // con {serial_type, serial_number} y el backend los resuelve a IDs.
            'items.*.serial_units' => ['nullable', 'array'],
            'items.*.serial_units.*.serial_type' => ['required_with:items.*.serial_units', 'string', Rule::in(['imei', 'serial'])],
            'items.*.serial_units.*.serial_number' => ['required_with:items.*.serial_units', 'string', 'max:255'],
        ];
    }
}
