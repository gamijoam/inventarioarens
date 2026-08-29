<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\CrmApiToken;
use App\Modules\CRM\Services\CrmScopeService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()?->can('settings.manage')) {
            return false;
        }

        if ($this->input('tenant_scope', CrmApiToken::TENANT_SCOPE_TENANT) !== CrmApiToken::TENANT_SCOPE_SUBTREE) {
            return true;
        }

        $tenant = app(TenantManager::class)->require();

        return $tenant->isGroup() && $this->user()->isStrictOwnerOf($tenant);
    }

    public function rules(): array
    {
        $tenant = app(TenantManager::class)->require();
        $tenantScope = (string) $this->input('tenant_scope', CrmApiToken::TENANT_SCOPE_TENANT);
        $scopeTenantIds = app(CrmScopeService::class)->tenantIdsFor($tenant, $tenantScope);

        return [
            'name' => ['required', 'string', 'max:100'],
            'tenant_scope' => ['nullable', 'string', Rule::in(CrmApiToken::TENANT_SCOPES)],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(CrmApiToken::SCOPES)],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->whereIn('tenant_id', $scopeTenantIds)),
            ],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->whereIn('tenant_id', $scopeTenantIds)),
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
