<?php

namespace App\Modules\Products\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('tenant_id', $this->user()?->tenants()->first()?->id ?? 0)],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la categoria es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar 150 caracteres.',
            'name.string' => 'El nombre debe ser texto.',
            'slug.required' => 'El slug es obligatorio (se genera automaticamente del nombre).',
            'slug.string' => 'El slug debe ser texto.',
            'slug.max' => 'El slug no puede superar 100 caracteres.',
            'slug.regex' => 'El slug solo puede contener letras minusculas, numeros y guiones (sin espacios ni caracteres especiales).',
            'parent_id.integer' => 'La categoria padre debe ser un numero valido.',
            'parent_id.exists' => 'La categoria padre seleccionada no existe.',
            'description.string' => 'La descripcion debe ser texto.',
            'description.max' => 'La descripcion no puede superar 500 caracteres.',
            'sort_order.integer' => 'El orden debe ser un numero entero.',
            'sort_order.min' => 'El orden no puede ser negativo.',
        ];
    }
}
