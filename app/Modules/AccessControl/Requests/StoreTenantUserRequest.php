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
            'confirm_password' => ['nullable', 'required_with:password', 'same:password'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['required', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'La contrasena debe tener al menos 8 caracteres.',
            'password.letters' => 'La contrasena debe contener letras.',
            'password.mixed' => 'La contrasena debe tener al menos una mayuscula y una minuscula.',
            'password.numbers' => 'La contrasena debe contener al menos un numero.',
            'confirm_password.required_with' => 'Repite la contrasena para confirmarla.',
            'confirm_password.same' => 'Las contrasenas no coinciden.',
        ];
    }
}
