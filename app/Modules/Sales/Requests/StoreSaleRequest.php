<?php

namespace App\Modules\Sales\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'items.*.price_list_id' => [
                'nullable',
                'integer',
                Rule::exists('price_lists', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.product_unit_ids' => ['sometimes', 'array'],
            'items.*.product_unit_ids.*' => ['integer', Rule::exists('product_units', 'id')->where('tenant_id', app(TenantManager::class)->require()->id)],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
