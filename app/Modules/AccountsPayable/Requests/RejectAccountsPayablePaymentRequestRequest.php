<?php

namespace App\Modules\AccountsPayable\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectAccountsPayablePaymentRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
