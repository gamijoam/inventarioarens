<?php

namespace App\Modules\Reports\Requests;

use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OperationalReportRequest extends FormRequest
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
            || $this->user()->can('reports.cash.view')
            || $this->user()->can('reports.inventory.view')
            || $this->user()->can('reports.movements.view')
            || $this->user()->can('finance_reports.view');
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'cash_register_id' => ['nullable', Rule::exists('cash_registers', 'id')->where('tenant_id', $tenantId)],
            'cashier_id' => ['nullable', Rule::exists('users', 'id')],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', Rule::in([
                'all',
                Sale::STATUS_DRAFT,
                Sale::STATUS_CONFIRMED,
                Sale::STATUS_CANCELLED,
                PosOrder::STATUS_OPEN,
                PosOrder::STATUS_PAID,
                PosOrder::STATUS_CANCELLED,
                CashRegisterSession::STATUS_OPEN,
                CashRegisterSession::STATUS_CLOSED,
                CashRegisterSession::STATUS_CANCELLED,
            ])],
            'payment_method' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        return [
            'date' => $this->input('date'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'branch_id' => $this->filled('branch_id') ? (int) $this->input('branch_id') : null,
            'warehouse_id' => $this->filled('warehouse_id') ? (int) $this->input('warehouse_id') : null,
            'cash_register_id' => $this->filled('cash_register_id') ? (int) $this->input('cash_register_id') : null,
            'cashier_id' => $this->filled('cashier_id') ? (int) $this->input('cashier_id') : null,
            'customer_id' => $this->filled('customer_id') ? (int) $this->input('customer_id') : null,
            'status' => $this->input('status', 'all'),
            'payment_method' => $this->input('payment_method'),
            'limit' => min((int) $this->input('limit', 25), 100),
        ];
    }
}
