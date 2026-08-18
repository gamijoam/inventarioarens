<?php

namespace App\Modules\ReportsV2\Requests;

use Illuminate\Validation\Rule;

class ReportExportRequest extends BaseReportRequest
{
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
