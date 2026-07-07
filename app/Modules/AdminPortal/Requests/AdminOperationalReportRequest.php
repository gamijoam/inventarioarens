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
        ];
    }

    public function filters(): array
    {
        return [
            'period' => $this->input('period', 'today'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
        ];
    }
}
