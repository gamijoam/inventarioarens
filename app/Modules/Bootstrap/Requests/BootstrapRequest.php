<?php

namespace App\Modules\Bootstrap\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BootstrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
            'bootstrap_token' => ['nullable', 'string', 'max:200'],

            'tenant' => ['nullable', 'array'],
            'tenant.name' => ['required_with:tenant', 'string', 'max:150'],
            'tenant.slug' => ['required_with:tenant', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'tenant.domain' => ['nullable', 'string', 'max:150'],
            'tenant.plan' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del administrador es obligatorio.',
            'email.required' => 'El correo electronico es obligatorio.',
            'email.email' => 'El correo electronico no tiene un formato valido.',
            'password.min' => 'La contrasena debe tener al menos 8 caracteres.',
            'tenant.slug.regex' => 'El slug solo puede contener letras minusculas, numeros y guiones.',
        ];
    }

    public function attributes(): array
    {
        return [
            'tenant.name' => 'nombre de la empresa',
            'tenant.slug' => 'slug de la empresa',
        ];
    }
}
