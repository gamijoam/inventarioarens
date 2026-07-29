<?php

namespace App\Modules\SalesReturns\Requests;

use App\Modules\POS\Requests\StorePosCheckoutRequest;

class ExchangeSalesReturnRequest extends StorePosCheckoutRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'credit_amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
