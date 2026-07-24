<?php

namespace App\Modules\Products\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];
        $category = $this->route('category');

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('categories', 'slug')->where(fn ($query) => $query->whereIn('tenant_id', $tenantIds))->ignore($category?->id)],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->whereIn('tenant_id', $tenantIds)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
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
