<?php

namespace App\Modules\Warranties\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarrantyClaimRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'sale_item_id' => ['required', 'integer', Rule::exists('sale_items', 'id')->where('tenant_id', $tenantId)],
            'product_unit_id' => ['nullable', 'integer', Rule::exists('product_units', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:60'],
            'issue_description' => ['required', 'string', 'max:2000'],
            'received_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
