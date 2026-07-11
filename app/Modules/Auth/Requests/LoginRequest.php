<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120', 'regex:/^[\PC\s]+$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'device_name.regex' => 'El nombre del dispositivo contiene caracteres no permitidos.',
        ];
    }
}
