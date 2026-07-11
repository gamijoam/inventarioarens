<?php

namespace App\Modules\AccessControl\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreTenantUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [
                'nullable',
                'string',
                Password::min(8)->mixedCase()->numbers(),
            ],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['required', 'string', 'max:150'],
        ];
    }
}
