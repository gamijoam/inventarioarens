<?php

namespace App\Modules\Tenancy\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePlatformAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'email.email' => 'El correo no tiene un formato valido.',
            'password.min' => 'La contrasena debe tener al menos 8 caracteres.',
        ];
    }
}
