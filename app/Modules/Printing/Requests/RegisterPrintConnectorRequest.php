<?php

namespace App\Modules\Printing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPrintConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:8', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'installation_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'version' => ['nullable', 'string', 'max:32'],
            'metadata' => ['nullable', 'array', 'max:20'],
        ];
    }
}
