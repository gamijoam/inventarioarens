<?php

namespace App\Modules\ReportsV2\Requests;

use App\Modules\ReportsV2\ReportRegistry;
use Illuminate\Validation\Rule;

class ReportExportRequest extends BaseReportRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $definition = app(ReportRegistry::class)->get((string) $this->route('report'));
        $permission = $definition?->domain === 'finanzas'
            ? 'finance_reports.export'
            : 'reports.export';

        return $this->user()?->can($permission) ?? false;
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
        ]);
    }

    public function exportFormat(): string
    {
        return (string) $this->input('format', 'csv');
    }
}
