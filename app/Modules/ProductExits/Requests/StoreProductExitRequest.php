<?php

namespace App\Modules\ProductExits\Requests;

use App\Modules\ProductExits\Models\ProductExit;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductExitRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'reason' => ['required', Rule::in(ProductExit::REASONS)],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'processed_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.product_unit_ids' => ['nullable', 'array'],
            'items.*.product_unit_ids.*' => ['integer'],
        ];
    }
}
