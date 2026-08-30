<?php

namespace App\Modules\Fiscal\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateFiscalDocumentPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantManager::class)->current();

        if (! $tenant || ! $this->user()?->belongsToTenant($tenant)) {
            return false;
        }

        setPermissionsTeamId($tenant->id);

        return $this->user()->can('sales.view')
            || $this->user()->can('reports.view')
            || $this->user()->can('reports.sales.view');
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'sale_id' => [
                'required',
                'integer',
                Rule::exists('sales', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
