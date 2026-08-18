<?php

namespace App\Modules\ReportsV2\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportV2CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('reports.view')
            || $user->can('reports.sales.view')
            || $user->can('reports.cash.view')
            || $user->can('reports.inventory.view')
            || $user->can('finance_reports.view');
    }

    public function rules(): array
    {
        return [];
    }
}
