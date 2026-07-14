<?php

namespace App\Modules\Products\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'slug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'El slug solo puede contener letras minusculas, numeros y guiones.',
            'color.regex' => 'El color debe ser un codigo hexadecimal tipo #FFAA00.',
        ];
    }
}
