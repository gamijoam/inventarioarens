<?php

namespace App\Modules\Commissions\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('commissions.settle') === true;
    }

    public function rules(): array
    {
        return [
            'entry_ids' => ['required', 'array', 'min:1', 'max:500'],
            'entry_ids.*' => ['integer', 'distinct'],
            'payment_currency' => ['required', Rule::in(['USD', 'VES'])],
            'exchange_rate_type_id' => [
                Rule::requiredIf($this->input('payment_currency') === 'VES'),
                'nullable',
                'integer',
            ],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
