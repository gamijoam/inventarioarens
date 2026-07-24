<?php

namespace App\Modules\Products\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTagRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('tags', 'slug')->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds))],
            'color' => ['nullable', 'string', 'max:20', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del tag es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 1 caracter.',
            'name.max' => 'El nombre no puede superar 80 caracteres.',
            'name.string' => 'El nombre debe ser texto.',
            'slug.required' => 'El slug es obligatorio (se genera automaticamente del nombre).',
            'slug.string' => 'El slug debe ser texto.',
            'slug.max' => 'El slug no puede superar 80 caracteres.',
            'slug.regex' => 'El slug solo puede contener letras minusculas, numeros y guiones (sin espacios ni caracteres especiales).',
            'color.string' => 'El color debe ser texto.',
            'color.max' => 'El color no puede superar 20 caracteres.',
            'color.regex' => 'El color debe ser un codigo hexadecimal tipo #FFAA00.',
        ];
    }
}
