<?php

namespace App\Modules\ReportsV2\Requests;

use App\Modules\ReportsV2\ReportRegistry;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BaseReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistry::class)->get((string) $this->route('report'));
        if ($definition === null) {
            // Deja que el controller responda 404 para codigos inexistentes.
            return true;
        }

        $scope = $this->input('scope', 'tenant');

        if ($scope !== 'organization') {
            return $user->can($definition->permission);
        }

        if (! $definition->orgSupported) {
            return false;
        }

        $current = app(TenantManager::class)->current();
        if ($current === null) {
            return false;
        }

        $group = $current->isGroup() ? $current : $current->parent()->first();
        if ($group === null || ! $group->isGroup() || ! $user->isStrictOwnerOf($group)) {
            return false;
        }

        $previousTeamId = function_exists('getPermissionsTeamId') ? getPermissionsTeamId() : null;
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($group->id);
        }

        try {
            return $user->can('reports.organization.view');
        } finally {
            if (function_exists('setPermissionsTeamId') && $previousTeamId !== null) {
                setPermissionsTeamId($previousTeamId);
            }
        }
    }

    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(['tenant', 'organization'])],
            'dimension' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'warehouse_id' => ['nullable', 'integer'],
            'low_stock_only' => ['nullable', 'boolean'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'company_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function filters(): array
    {
        $filters = [
            'scope' => $this->input('scope', 'tenant'),
            'dimension' => $this->input('dimension'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'limit' => (int) $this->input('limit', 200),
        ];

        if ($this->filled('warehouse_id')) {
            $filters['warehouse_id'] = (int) $this->input('warehouse_id');
        }
        if ($this->filled('low_stock_only')) {
            $filters['low_stock_only'] = $this->boolean('low_stock_only');
        }
        if ($this->filled('low_stock_threshold')) {
            $filters['low_stock_threshold'] = (float) $this->input('low_stock_threshold');
        }
        if ($this->filled('company_id')) {
            $filters['company_id'] = (int) $this->input('company_id');
        }

        return $filters;
    }
}
