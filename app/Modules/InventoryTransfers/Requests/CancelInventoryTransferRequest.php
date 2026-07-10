<?php

namespace App\Modules\InventoryTransfers\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelInventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cancelled_at' => ['nullable', 'date'],
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'Debe indicar un motivo para cancelar el traslado.',
            'cancellation_reason.min' => 'El motivo de cancelacion debe tener al menos 5 caracteres.',
        ];
    }
}
