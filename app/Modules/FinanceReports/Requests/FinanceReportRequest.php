<?php

namespace App\Modules\FinanceReports\Requests;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantManager::class)->current();

        if (! $tenant || ! $this->user()?->belongsToTenant($tenant)) {
            return false;
        }

        setPermissionsTeamId($tenant->id);

        return $this->user()->can('finance_reports.view');
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in([
                AccountsReceivable::STATUS_PENDING,
                AccountsReceivable::STATUS_PARTIAL,
                AccountsReceivable::STATUS_PAID,
                AccountsReceivable::STATUS_OVERDUE,
            ])],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
        ];
    }

    public function filters(): array
    {
        return [
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'status' => $this->input('status'),
            'customer_id' => $this->input('customer_id'),
            'supplier_id' => $this->input('supplier_id'),
        ];
    }
}
