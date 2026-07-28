<?php

namespace App\Modules\CashRegister\Requests;

use App\Modules\CashRegister\Models\CashRegisterSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewCashRegisterSessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                CashRegisterSession::REVIEW_APPROVED,
                CashRegisterSession::REVIEW_REJECTED,
            ])],
            'review_notes' => ['nullable', 'string', 'required_if:status,'.CashRegisterSession::REVIEW_REJECTED],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
