<?php

namespace App\Modules\Tenancy\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpinoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->route('tenant')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('tenants', 'slug')->ignore($tenantId)],
            'domain' => ['nullable', 'string', 'max:150', Rule::unique('tenants', 'domain')->ignore($tenantId)],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'plan' => ['nullable', 'string', 'max:50'],
            'parent_id' => [
                'sometimes',
                'integer',
                Rule::exists('tenants', 'id')->where(fn ($query) => $query->where('is_group', true)),
            ],
        ];
    }
}
