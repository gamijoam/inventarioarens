<?php

namespace App\Modules\AdminPortal\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (
            $user->can('reports.view')
            || $user->can('finance_reports.view')
            || $user->can('sales.view')
            || $user->can('products.view')
            || $user->can('cash_register.view')
        );
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['today', 'week', 'month'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ];
    }

    public function filters(): array
    {
        return [
            'period' => $this->input('period', 'today'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'low_stock_threshold' => $this->float('low_stock_threshold', 3),
        ];
    }
}
