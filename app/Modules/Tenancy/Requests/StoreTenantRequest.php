<?php

namespace App\Modules\Tenancy\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('tenants', 'slug')],
            'domain' => ['nullable', 'string', 'max:150', Rule::unique('tenants', 'domain')],
            'plan' => ['nullable', 'string', 'max:50'],

            'admin' => ['required', 'array'],
            'admin.name' => ['required', 'string', 'max:150'],
            'admin.email' => ['required', 'email', 'max:255'],
            'admin.password' => ['nullable', 'string', 'min:8'],

            'branch' => ['nullable', 'array'],
            'branch.name' => ['required_with:branch', 'string', 'max:150'],
            'branch.code' => ['required_with:branch', 'string', 'max:50'],

            'warehouse' => ['nullable', 'array'],
            'warehouse.name' => ['required_with:warehouse', 'string', 'max:150'],
            'warehouse.code' => ['required_with:warehouse', 'string', 'max:50'],

            'exchange_rate_type' => ['nullable', 'array'],
            'exchange_rate_type.code' => ['required_with:exchange_rate_type', 'string', 'max:20'],
            'exchange_rate_type.name' => ['required_with:exchange_rate_type', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'Ya existe una empresa con ese slug.',
            'slug.regex' => 'El slug solo puede contener letras minusculas, numeros y guiones.',
            'domain.unique' => 'Ya existe una empresa con ese dominio.',
        ];
    }

    public function attributes(): array
    {
        return [
            'admin.email' => 'correo del administrador',
            'admin.name' => 'nombre del administrador',
            'admin.password' => 'contrasena del administrador',
        ];
    }
}