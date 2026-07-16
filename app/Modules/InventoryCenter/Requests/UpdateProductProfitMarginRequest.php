<?php

namespace App\Modules\InventoryCenter\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductProfitMarginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'profit_margin' => ['required', 'numeric', 'min:0', 'max:999.99'],
        ];
    }
}
