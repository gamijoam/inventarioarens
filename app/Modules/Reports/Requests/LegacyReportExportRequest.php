<?php

namespace App\Modules\Reports\Requests;

use App\Modules\Inventory\Models\StockMovement;
use App\Support\Tenancy\TenantManager;
use Illuminate\Validation\Rule;

class LegacyReportExportRequest extends OperationalReportRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $user = $this->user();
        $tenant = app(TenantManager::class)->current();
        if (! $user || ! $tenant) {
            return false;
        }
        setPermissionsTeamId($tenant->id);

        $report = (string) $this->route('report');
        $viewPermission = match ($report) {
            'sales' => 'reports.sales.view',
            'cash' => 'reports.cash.view',
            'payments' => 'reports.cash.view',
            'stock' => 'reports.inventory.view',
            'movements' => 'reports.movements.view',
            default => null,
        };
        $canView = $user->can('reports.view')
            || ($viewPermission !== null && $user->can($viewPermission))
            || ($report === 'payments' && $user->can('reports.sales.view'));
        $canExport = $user->can('reports.export');

        return $canView && $canExport;
    }

    public function filters(): array
    {
        $filters = parent::filters();
        $filters['search'] = $this->filled('search') ? (string) $this->input('search') : null;
        $filters['type'] = $this->filled('type') ? (string) $this->input('type') : null;

        return $filters;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['date_to'] = [
            'nullable',
            'date',
            function (string $attribute, mixed $value, \Closure $fail): void {
                $from = $this->input('date_from');
                if ($value && $from && strcmp((string) $value, (string) $from) < 0) {
                    $fail('La fecha final debe ser igual o posterior a la fecha inicial.');
                }
            },
        ];
        $rules['type'] = ['nullable', Rule::in(StockMovement::TYPES)];
        $rules['search'] = ['nullable', 'string', 'max:100'];
        $rules['format'] = ['required', Rule::in(['csv'])];

        return $rules;
    }
}
