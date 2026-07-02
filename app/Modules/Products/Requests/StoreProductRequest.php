<?php

namespace App\Modules\Products\Requests;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'sku')->where('tenant_id', $tenantId),
            ],
            'tracking_type' => [
                'sometimes',
                'string',
                Rule::in([Product::TRACKING_QUANTITY, Product::TRACKING_SERIALIZED]),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
