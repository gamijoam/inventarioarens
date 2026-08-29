<?php

namespace App\Modules\Quotations\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuotationRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereIn('tenant_id', $tenantIds)],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'status' => ['sometimes', Rule::in(['draft', 'issued', 'cancelled'])],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereIn('tenant_id', $tenantIds)],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
