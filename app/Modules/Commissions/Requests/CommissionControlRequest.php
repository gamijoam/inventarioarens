<?php

namespace App\Modules\Commissions\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommissionControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('commissions.view_all') === true
            || $this->user()?->can('commissions.view_own') === true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'cashier_id' => ['nullable', 'integer', 'min:1'],
            'payment_method_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
