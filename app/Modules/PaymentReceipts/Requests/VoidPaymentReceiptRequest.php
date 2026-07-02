<?php

namespace App\Modules\PaymentReceipts\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidPaymentReceiptRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
