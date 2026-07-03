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
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'type' => ['sometimes', Rule::in(InventoryTransfer::TYPES)],
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
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.product_unit_ids' => ['nullable', 'array'],
            'items.*.product_unit_ids.*' => ['integer'],
        ];
    }
}
