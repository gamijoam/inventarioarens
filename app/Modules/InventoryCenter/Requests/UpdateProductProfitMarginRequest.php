<?php

namespace App\Modules\InventoryCenter\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductProfitMarginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'profit_margin' => ['required', 'numeric', 'lte:999.99'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'profit_margin.required' => 'El margen es obligatorio.',
            'profit_margin.numeric' => 'El margen debe ser numerico.',
            'profit_margin.lte' => 'El margen no puede superar 999.99.',
        ];
    }
}
