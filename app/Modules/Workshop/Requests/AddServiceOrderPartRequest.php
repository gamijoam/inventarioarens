<?php

namespace App\Modules\Workshop\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddServiceOrderPartRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId),
            ],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => [
                'sometimes',
                'integer',
                Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
