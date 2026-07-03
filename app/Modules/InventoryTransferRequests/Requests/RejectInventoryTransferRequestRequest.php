<?php

namespace App\Modules\InventoryTransferRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectInventoryTransferRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'response_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
