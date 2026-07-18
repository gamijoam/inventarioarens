<?php

namespace App\Modules\SalesReturns\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectSalesReturnRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
