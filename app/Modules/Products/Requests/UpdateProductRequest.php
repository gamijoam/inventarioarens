<?php

namespace App\Modules\Products\Requests;

use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $product = $this->route('product');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'sku')
                    ->where('tenant_id', $tenantId)
                    ->ignore($product?->id),
            ],
            'tracking_type' => [
                'sometimes',
                'required',
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
