<?php

namespace App\Modules\AccessControl\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceUserOverridesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.permission' => [
                'required',
                'string',
                'max:150',
                Rule::in(\App\Support\Permissions\BasePermissions::PERMISSIONS),
            ],
            'items.*.effect' => ['required', 'string', 'in:allow,deny'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.permission.in' => 'El permiso :input no existe en el catalogo del sistema.',
            'items.*.effect.in' => 'El effect debe ser allow o deny.',
        ];
    }
}