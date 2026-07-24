<?php

namespace App\Modules\Tenancy\Requests;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $group = $this->route('group');
        $groupId = $group instanceof Tenant ? $group->id : (int) $group;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('tenants', 'slug')->ignore($groupId)],
            'domain' => ['sometimes', 'nullable', 'string', 'max:150', Rule::unique('tenants', 'domain')->ignore($groupId)],
            'plan' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive'],
        ];
    }
}
