<?php

namespace App\Modules\Products\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];
        $brand = $this->route('brand');

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('brands', 'slug')->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds))->ignore($brand?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'El slug solo puede contener letras minusculas, numeros y guiones.',
        ];
    }
}
