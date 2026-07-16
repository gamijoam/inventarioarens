<?php

namespace App\Modules\InventoryCenter\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecalculateProductPriceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Si viene, override del profit_margin del producto.
            'profit_margin' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
        ];
    }
}
