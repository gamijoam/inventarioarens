<?php

namespace App\Modules\Products\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('brands', 'slug')->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds))],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la marca es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar 150 caracteres.',
            'name.string' => 'El nombre debe ser texto.',
            'slug.required' => 'El slug es obligatorio (se genera automaticamente del nombre).',
            'slug.string' => 'El slug debe ser texto.',
            'slug.max' => 'El slug no puede superar 100 caracteres.',
            'slug.regex' => 'El slug solo puede contener letras minusculas, numeros y guiones (sin espacios ni caracteres especiales).',
            'description.string' => 'La descripcion debe ser texto.',
            'description.max' => 'La descripcion no puede superar 500 caracteres.',
        ];
    }
}
