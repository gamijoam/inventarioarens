<?php

namespace App\Modules\ReportsV2\Services;

use App\Modules\ReportsV2\ReportDefinition;
use App\Modules\ReportsV2\ReportRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ejecuta un reporte V2 con una sola query SQL agregada. El scope puede ser
 * un tenant (empresa) o una organizacion (grupo + spinoffs). El numero de
 * queries no depende de la cantidad de empresas ni de filas del resultado.
 */
class ReportQueryService
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly TenantManager $tenants,
    ) {}

    public function run(string $code, array $filters): array
    {
        $definition = $this->registry->get($code) ?? throw new \InvalidArgumentException("Reporte no encontrado: {$code}");

        $scope = ($filters['scope'] ?? 'tenant') === 'organization' ? 'organization' : 'tenant';
        $tenantIds = $this->tenantIds($scope);
        $dimension = $definition->resolveDimension($filters);
        $dimensionDef = $definition->dimensions[$dimension];
        $limit = max(1, min((int) ($filters['limit'] ?? 200), 1000));
        $dateFrom = $this->dateFrom($filters);
        $dateTo = $this->dateTo($filters);

        [$sql, $bindings] = $this->buildQuery($definition, $dimension, $dimensionDef, $tenantIds, $dateFrom, $dateTo, $limit, $filters);

        $rows = collect(DB::select($sql, $bindings))
            ->map(fn (object $row): array => $this->normalizeRow($row, $definition))
            ->values()
            ->all();

        return [
            'report' => [
                'code' => $definition->code,
                'name' => $definition->name,
                'domain' => $definition->domain,
                'dimension' => $dimension,
            ],
            'scope' => $scope,
            'period' => $dateFrom && $dateTo ? [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
            ] : null,
            'rows' => $rows,
            'totals' => $this->totals($rows, $definition),
        ];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildQuery(
        ReportDefinition $definition,
        string $dimension,
        array $dimensionDef,
        array $tenantIds,
        ?Carbon $dateFrom,
        ?Carbon $dateTo,
        int $limit,
        array $filters,
    ): array {
        $select = ["{$dimensionDef['label']} as label", "{$dimensionDef['expr']} as group_key"];
        foreach ($definition->measures as $code => $expr) {
            $select[] = "{$expr} as {$code}";
        }

        $sql = 'select '.implode(', ', $select)
            .' from '.$definition->base
            .' '.($dimensionDef['join'] ?? '')
            .' where '.$definition->statusSql
            .' and '.$this->tenantColumn($definition).' in ('.implode(',', array_fill(0, count($tenantIds), '?')).')';

        $bindings = array_values($tenantIds);

        if ($dateFrom && $dateTo && $definition->dateColumn) {
            $sql .= " and {$definition->dateColumn} between ? and ?";
            $bindings[] = $dateFrom->toDateTimeString();
            $bindings[] = $dateTo->toDateTimeString();
        }

        foreach ($definition->equalityFilters as $param => $column) {
            if (isset($filters[$param]) && $filters[$param] !== null && $filters[$param] !== '') {
                $sql .= " and {$column} = ?";
                $bindings[] = (int) $filters[$param];
            }
        }

        if ($definition->lowStockFilter && ($filters['low_stock_only'] ?? false) === true) {
            $sql .= ' and sb.quantity_available <= ?';
            $bindings[] = max(0, (float) ($filters['low_stock_threshold'] ?? 3));
        }

        $sql .= " group by {$dimensionDef['expr']}, {$dimensionDef['label']}";
        $sql .= " order by {$definition->measures[$definition->defaultMeasure]} desc";
        $sql .= " limit {$limit}";

        return [$sql, $bindings];
    }

    private function tenantColumn(ReportDefinition $definition): string
    {
        $base = trim($definition->base);

        if (preg_match('/^[a-z_]+[^ ]*\s+([a-z_]+)(\s|$)/i', $base, $matches)) {
            return "{$matches[1]}.tenant_id";
        }

        $first = explode(' ', $base)[0] ?? '';

        return "{$first}.tenant_id";
    }

    /**
     * @return array<int, int>
     */
    private function tenantIds(string $scope): array
    {
        $current = $this->tenants->current();
        if (! $current) {
            return [];
        }

        if ($scope === 'organization') {
            $group = $current->isGroup() ? $current : ($current->parent()->first() ?? $current);

            return $group->spinoffs()
                ->where('status', 'active')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return [(int) $current->id];
    }

    private function dateFrom(array $filters): ?Carbon
    {
        if (! empty($filters['date_from'])) {
            return Carbon::parse($filters['date_from'])->startOfDay();
        }

        return null;
    }

    private function dateTo(array $filters): ?Carbon
    {
        if (! empty($filters['date_to'])) {
            return Carbon::parse($filters['date_to'])->endOfDay();
        }

        return null;
    }

    private function normalizeRow(object $row, ReportDefinition $definition): array
    {
        $out = [
            'label' => (string) $row->label,
            'group_key' => is_numeric($row->group_key) ? (int) $row->group_key : (string) $row->group_key,
        ];

        foreach (array_keys($definition->measures) as $code) {
            $out[$code] = round((float) ($row->{$code} ?? 0), 4);
        }

        return $out;
    }

    private function totals(array $rows, ReportDefinition $definition): array
    {
        $totals = [];
        foreach (array_keys($definition->measures) as $code) {
            if (isset($definition->averageMeasures[$code])) {
                $weight = $definition->averageMeasures[$code];
                $weighted = 0.0;
                $totalWeight = 0.0;
                foreach ($rows as $row) {
                    $rowWeight = (float) ($row[$weight] ?? 0);
                    $weighted += (float) ($row[$code] ?? 0) * $rowWeight;
                    $totalWeight += $rowWeight;
                }
                $totals[$code] = $totalWeight > 0 ? round($weighted / $totalWeight, 4) : 0.0;

                continue;
            }
            $totals[$code] = round(array_sum(array_column($rows, $code)), 4);
        }

        return $totals;
    }
}
