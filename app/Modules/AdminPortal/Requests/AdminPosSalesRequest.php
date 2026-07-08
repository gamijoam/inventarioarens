<?php

namespace App\Modules\AdminPortal\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminPosSalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (
            $user->can('sales.view')
            || $user->can('reports.view')
            || $user->can('finance_reports.view')
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
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'export' => ['nullable', Rule::in(['csv'])],
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
            'search' => trim((string) $this->input('search', '')),
            'limit' => (int) $this->input('limit', 25),
            'page' => (int) $this->input('page', 1),
        ];
    }

    public function wantsCsvExport(): bool
    {
        return $this->input('export') === 'csv';
    }
}
