<?php

namespace App\Modules\Products\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductVariantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'color' => ['nullable', 'string', 'max:50'],
            'color_hex' => ['nullable', 'string', 'max:9'],
            'sku_variant' => ['nullable', 'string', 'max:100'],
            'barcode_variant' => ['nullable', 'string', 'max:100'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
