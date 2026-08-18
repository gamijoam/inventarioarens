<?php

namespace App\Modules\Dashboard\Requests;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $scope = $this->input('scope', 'tenant');

        if ($scope !== 'organization') {
            return $user->can('finance_reports.view')
                || $user->can('reports.view')
                || $user->can('sales.view')
                || $user->can('pos.view')
                || $user->can('products.view')
                || $user->can('cash_register.view');
        }

        $group = $this->resolveGroup();
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
            'period' => ['nullable', Rule::in(['today', 'week', 'month'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function filters(): array
    {
        return [
            'scope' => $this->input('scope', 'tenant'),
            'period' => $this->input('period', 'today'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'low_stock_threshold' => $this->float('low_stock_threshold', 3),
        ];
    }

    public function scope(): string
    {
        return $this->input('scope', 'tenant');
    }

    /**
     * Resuelve el grupo raiz que actua como contexto consolidado del tenant
     * actual. Para un tenant grupo devuelve el propio tenant; para un spinoff
     * devuelve su grupo padre.
     */
    public function resolveGroup(): ?Tenant
    {
        $current = app(TenantManager::class)->current();

        if ($current === null) {
            return null;
        }

        if ($current->isGroup()) {
            return $current;
        }

        return $current->parent()->first();
    }
}
