<?php

namespace App\Modules\ReportsV2\Services;

use App\Modules\ReportsV2\ReportDefinition;
use App\Modules\ReportsV2\ReportRegistry;
use App\Support\Tenancy\TenantManager;
use App\Support\Time\BusinessDateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ejecuta un reporte agregado. El contrato mantiene rows/totals y agrega
 * metadatos de paginación y una serie independiente para las gráficas.
 */
class ReportQueryService
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly TenantManager $tenants,
    ) {}

    public function run(string $code, array $filters, bool $allRows = false): array
    {
        $definition = $this->registry->get($code) ?? throw new \InvalidArgumentException("Reporte no encontrado: {$code}");
        $scope = ($filters['scope'] ?? 'tenant') === 'organization' ? 'organization' : 'tenant';
        $companyId = isset($filters['company_id']) && $filters['company_id'] !== null
            ? (int) $filters['company_id']
            : null;
        $tenantIds = $this->tenantIds($scope, $companyId);
        $dimension = $definition->resolveDimension($filters);
        $dimensionDef = $definition->dimensions[$dimension];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min((int) ($filters['per_page'] ?? $filters['limit'] ?? 200), 1000));
        $dateFrom = $this->dateFrom($filters);
        $dateTo = $this->dateTo($filters);

        $rows = [];
        if ($tenantIds !== []) {
            [$sql, $bindings] = $this->buildQuery(
                $definition,
                $dimension,
                $dimensionDef,
                $tenantIds,
                $dateFrom,
                $dateTo,
                $filters,
            );

            $rows = collect(DB::select($sql, $bindings))
                ->map(fn (object $row): array => $this->normalizeRow($row, $definition))
                ->values()
                ->all();
        }

        $temporal = in_array($dimension, ['day', 'week', 'month'], true);
        if ($temporal) {
            usort($rows, fn (array $a, array $b): int => strcmp((string) $a['group_key'], (string) $b['group_key']));
        }

        $totalRows = count($rows);
        $totals = $this->totals($rows, $definition);
        $rate = $this->attachRates($rows, $totals, $definition);
        $chartRows = $temporal ? $rows : array_slice($rows, 0, 10);
        $pageRows = $allRows
            ? $rows
            : array_slice($rows, ($page - 1) * $perPage, $perPage);
        $this->attachRates($pageRows, $totals, $definition);
        $this->attachRates($chartRows, $totals, $definition);

        return [
            'report' => [
                'code' => $definition->code,
                'name' => $definition->name,
                'domain' => $definition->domain,
                'dimension' => $dimension,
            ],
            'scope' => $scope,
            'period' => ($dateFrom || $dateTo) ? [
                'from' => $dateFrom?->copy()->setTimezone(BusinessDateRange::timezone())->toDateString(),
                'to' => $dateTo?->copy()->setTimezone(BusinessDateRange::timezone())->toDateString(),
            ] : null,
            'rows' => $pageRows,
            'chart_rows' => $chartRows,
            'totals' => $totals,
            'rate' => $rate,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_rows' => $totalRows,
                'last_page' => max(1, (int) ceil($totalRows / $perPage)),
            ],
        ];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function buildQuery(
        ReportDefinition $definition,
        string $dimension,
        array $dimensionDef,
        array $tenantIds,
        ?Carbon $dateFrom,
        ?Carbon $dateTo,
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

        if ($definition->dateColumn !== null) {
            if ($dateFrom !== null) {
                $sql .= " and {$definition->dateColumn} >= ?";
                $bindings[] = $dateFrom->toDateTimeString();
            }
            if ($dateTo !== null) {
                $sql .= " and {$definition->dateColumn} <= ?";
                $bindings[] = $dateTo->toDateTimeString();
            }
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
        if (in_array($dimension, ['day', 'week', 'month'], true)) {
            $sql .= ' order by group_key asc';
        } else {
            $sql .= " order by {$definition->measures[$definition->defaultMeasure]} desc";
        }

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

    /** @return array<int, int> */
    private function tenantIds(string $scope, ?int $companyId): array
    {
        $current = $this->tenants->current();
        if (! $current) {
            return [];
        }

        if ($scope === 'organization') {
            $group = $current->isGroup() ? $current : ($current->parent()->first() ?? $current);
            $spinoffIds = $group->spinoffs()
                ->where('status', 'active')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($companyId !== null) {
                if (! in_array($companyId, $spinoffIds, true)) {
                    throw new \InvalidArgumentException('La empresa seleccionada no pertenece al grupo.');
                }
                return [$companyId];
            }

            return $spinoffIds;
        }

        return [(int) $current->id];
    }

    private function dateFrom(array $filters): ?Carbon
    {
        return ! empty($filters['date_from'])
            ? BusinessDateRange::startOfDay($filters['date_from'])
            : null;
    }

    private function dateTo(array $filters): ?Carbon
    {
        return ! empty($filters['date_to'])
            ? BusinessDateRange::endOfDay($filters['date_to'])
            : null;
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

    private function attachRates(array &$rows, array $totals, ReportDefinition $definition): ?float
    {
        if ($definition->localPairs === []) {
            return null;
        }

        $local = array_key_first($definition->localPairs);
        $base = $definition->localPairs[$local];
        foreach ($rows as &$row) {
            $baseValue = (float) ($row[$base] ?? 0);
            $row['rate'] = $baseValue > 0
                ? round((float) ($row[$local] ?? 0) / $baseValue, 6)
                : 0;
        }
        unset($row);

        $totalBase = (float) ($totals[$base] ?? 0);
        return $totalBase > 0
            ? round((float) ($totals[$local] ?? 0) / $totalBase, 6)
            : null;
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
