<?php

namespace App\Modules\Sync\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartSyncBootstrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'node_code' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+$/'],
            'node_name' => ['required', 'string', 'max:120'],
            'installation_code' => ['required', 'string', 'max:80'],
        ];
    }
}
