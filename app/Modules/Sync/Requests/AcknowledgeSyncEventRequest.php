<?php

namespace App\Modules\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcknowledgeSyncEventRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'node_code' => ['required', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['applied', 'failed'])],
            'error' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
