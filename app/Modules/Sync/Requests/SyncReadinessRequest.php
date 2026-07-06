<?php

namespace App\Modules\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncReadinessRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'installation_code' => ['required', 'string', 'max:80'],
            'node_code' => ['nullable', 'string', 'max:80'],
            'node_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:pending,syncing,ready,warning,error'],
            'last_error' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
