<?php

namespace App\Modules\Reports\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FiscalVatReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantManager::class)->current();

        if (! $tenant || ! $this->user()?->belongsToTenant($tenant)) {
            return false;
        }

        setPermissionsTeamId($tenant->id);

        return $this->user()->can('reports.view')
            || $this->user()->can('reports.sales.view')
            || $this->user()->can('finance_reports.view');
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
        ];
    }

    public function filters(): array
    {
        return [
            'date' => $this->input('date'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'branch_id' => $this->filled('branch_id') ? (int) $this->input('branch_id') : null,
            'customer_id' => $this->filled('customer_id') ? (int) $this->input('customer_id') : null,
            'product_id' => $this->filled('product_id') ? (int) $this->input('product_id') : null,
        ];
    }
}
