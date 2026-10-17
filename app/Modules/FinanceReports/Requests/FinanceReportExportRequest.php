<?php

namespace App\Modules\FinanceReports\Requests;

use Illuminate\Validation\Rule;

class FinanceReportExportRequest extends FinanceReportRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        return $this->user()?->can('finance_reports.export') ?? false;
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'format' => ['required', Rule::in(['csv'])],
        ]);
    }
}
