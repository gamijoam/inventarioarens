<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\CrmApiToken;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(CrmApiToken::SCOPES)],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'expires_at' => [
                'required',
                'date',
                'after:now',
                'before_or_equal:'.now()->addYear()->toDateTimeString(),
            ],
        ];
    }
}
