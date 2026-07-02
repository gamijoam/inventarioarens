<?php

namespace App\Modules\Inventory\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
