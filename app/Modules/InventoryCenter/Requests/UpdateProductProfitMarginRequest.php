<?php

namespace App\Modules\InventoryCenter\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductProfitMarginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'profit_margin' => ['required', 'numeric'],
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
        ];
    }
}
