<?php

namespace App\Modules\InventoryTransfers\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DispatchInventoryTransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'dispatched_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
