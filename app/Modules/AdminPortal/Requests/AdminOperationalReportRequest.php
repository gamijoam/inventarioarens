<?php

namespace App\Modules\AdminPortal\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminOperationalReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (
            $user->can('reports.view')
            || $user->can('finance_reports.view')
            || $user->can('sales.view')
            || $user->can('cash_register.view')
        );
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['today', 'week', 'month'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer'],
            'cash_register_id' => ['nullable', 'integer'],
            'cashier_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', 'open', 'paid', 'cancelled'])],
            'export' => ['nullable', Rule::in(['csv'])],
            'section' => ['nullable', Rule::in(['recent_orders', 'payment_methods', 'top_products', 'cash_sessions'])],
        ];
    }

    public function filters(): array
    {
        return [
            'period' => $this->input('period', 'today'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'branch_id' => $this->filled('branch_id') ? (int) $this->input('branch_id') : null,
            'cash_register_id' => $this->filled('cash_register_id') ? (int) $this->input('cash_register_id') : null,
            'cashier_id' => $this->filled('cashier_id') ? (int) $this->input('cashier_id') : null,
            'status' => $this->input('status', 'all'),
        ];
    }

    public function wantsCsvExport(): bool
    {
        return $this->input('export') === 'csv';
    }

    public function exportSection(): string
    {
        return $this->input('section', 'recent_orders');
    }
}
