<?php

namespace App\Modules\SalesReturns\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteSalesReturnExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pos_order_id' => ['required', 'integer', 'exists:pos_orders,id'],
        ];
    }
}
