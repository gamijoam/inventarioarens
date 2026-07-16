<?php

namespace App\Modules\InventoryCenter\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecalculateProductPriceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'profit_margin' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'lte:999.99'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
