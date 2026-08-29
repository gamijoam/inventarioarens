<?php

namespace App\Modules\SalesReversals\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReversePosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales.reverse');
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['void', 'reversal'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'cash_register_session_id' => ['required', 'integer', 'exists:cash_register_sessions,id'],
        ];
    }
}
