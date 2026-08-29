<?php

namespace App\Modules\Reports\Services;

use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FiscalVatReportService
{
    public function iva(array $filters): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        [$from, $to] = $this->dateRange($filters);

        $sales = Sale::query()
            ->where('tenant_id', $tenantId)
            ->where('status', Sale::STATUS_CONFIRMED)
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('confirmed_at', [$from, $to])
                    ->orWhere(function ($query) use ($from, $to): void {
                        $query->whereNull('confirmed_at')->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($filters['customer_id'] ?? null, fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['branch_id'] ?? null, fn ($query, int $branchId) => $query->whereHas(
                'posOrder.cashRegisterSession',
                fn ($session) => $session->where('branch_id', $branchId),
            ))
            ->when($filters['product_id'] ?? null, fn ($query, int $productId) => $query->whereHas(
                'items',
                fn ($items) => $items->where('product_id', $productId),
            ));

        $salesCount = (clone $sales)->count();
        $rows = $this->rows($tenantId, $sales);
        $summary = $this->summary($rows, $salesCount);

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'currency' => 'USD',
            'summary' => $summary,
            'rows' => $rows->all(),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function rows(int $tenantId, $sales): Collection
    {
        $taxCode = "COALESCE(NULLIF(si.fiscal_tax_code, ''), 'UNCLASSIFIED')";
        $category = "COALESCE(NULLIF(si.fiscal_tax_category, ''), 'unclassified')";
        $totalBase = 'COALESCE(NULLIF(si.fiscal_total_base_amount, 0), si.base_total_amount, 0)';
        $totalLocal = 'COALESCE(NULLIF(si.fiscal_total_local_amount, 0), si.total_amount, 0)';

        return DB::table('sale_items as si')
            ->where('si.tenant_id', $tenantId)
            ->whereIn('si.sale_id', (clone $sales)->select('id'))
            ->selectRaw("{$taxCode} as tax_code")
            ->selectRaw("{$category} as category")
            ->selectRaw('MAX(si.fiscal_tax_name) as tax_name')
            ->selectRaw('MAX(si.fiscal_tax_rate) as tax_rate')
            ->selectRaw('COUNT(DISTINCT si.sale_id) as sales_count')
            ->selectRaw('COALESCE(SUM(si.fiscal_taxable_base_amount), 0) as taxable_base_amount')
            ->selectRaw('COALESCE(SUM(si.fiscal_exempt_base_amount), 0) as exempt_base_amount')
            ->selectRaw('COALESCE(SUM(si.fiscal_exonerated_base_amount), 0) as exonerated_base_amount')
            ->selectRaw('COALESCE(SUM(si.fiscal_non_taxable_base_amount), 0) as non_taxable_base_amount')
            ->selectRaw('COALESCE(SUM(si.fiscal_tax_base_amount), 0) as tax_amount')
            ->selectRaw("COALESCE(SUM({$totalBase}), 0) as total_base_amount")
            ->selectRaw('COALESCE(SUM(si.fiscal_taxable_local_amount), 0) as taxable_local_amount')
            ->selectRaw('COALESCE(SUM(si.fiscal_exempt_local_amount), 0) as exempt_local_amount')
            ->selectRaw('COALESCE(SUM(si.fiscal_exonerated_local_amount), 0) as exonerated_local_amount')
            ->selectRaw('COALESCE(SUM(si.fiscal_non_taxable_local_amount), 0) as non_taxable_local_amount')
            ->selectRaw('COALESCE(SUM(si.fiscal_tax_local_amount), 0) as tax_local_amount')
            ->selectRaw("COALESCE(SUM({$totalLocal}), 0) as total_local_amount")
            ->groupByRaw("{$taxCode}, {$category}, si.fiscal_tax_rate")
            ->orderByRaw("CASE {$category} WHEN 'taxable' THEN 1 WHEN 'exempt' THEN 2 WHEN 'exonerated' THEN 3 WHEN 'non_taxable' THEN 4 ELSE 5 END")
            ->orderBy('tax_code')
            ->get()
            ->map(fn ($row): array => [
                'tax_code' => $row->tax_code,
                'category' => $row->category,
                'tax_name' => $row->tax_name,
                'tax_rate' => $row->tax_rate === null ? null : round((float) $row->tax_rate, 4),
                'sales_count' => (int) $row->sales_count,
                'taxable_base_amount' => $this->amount($row->taxable_base_amount),
                'exempt_base_amount' => $this->amount($row->exempt_base_amount),
                'exonerated_base_amount' => $this->amount($row->exonerated_base_amount),
                'non_taxable_base_amount' => $this->amount($row->non_taxable_base_amount),
                'tax_amount' => $this->amount($row->tax_amount),
                'total_base_amount' => $this->amount($row->total_base_amount),
                'taxable_local_amount' => $this->amount($row->taxable_local_amount),
                'exempt_local_amount' => $this->amount($row->exempt_local_amount),
                'exonerated_local_amount' => $this->amount($row->exonerated_local_amount),
                'non_taxable_local_amount' => $this->amount($row->non_taxable_local_amount),
                'tax_local_amount' => $this->amount($row->tax_local_amount),
                'total_local_amount' => $this->amount($row->total_local_amount),
            ]);
    }

    private function summary(Collection $rows, int $salesCount): array
    {
        $sum = fn (string $field): float => round((float) $rows->sum($field), 4);

        return [
            'sales_count' => $salesCount,
            'taxable_base_amount' => $sum('taxable_base_amount'),
            'exempt_base_amount' => $sum('exempt_base_amount'),
            'exonerated_base_amount' => $sum('exonerated_base_amount'),
            'non_taxable_base_amount' => $sum('non_taxable_base_amount'),
            'tax_amount' => $sum('tax_amount'),
            'total_base_amount' => $sum('total_base_amount'),
            'taxable_local_amount' => $sum('taxable_local_amount'),
            'exempt_local_amount' => $sum('exempt_local_amount'),
            'exonerated_local_amount' => $sum('exonerated_local_amount'),
            'non_taxable_local_amount' => $sum('non_taxable_local_amount'),
            'tax_local_amount' => $sum('tax_local_amount'),
            'total_local_amount' => $sum('total_local_amount'),
        ];
    }

    private function dateRange(array $filters): array
    {
        if (! empty($filters['date'])) {
            $date = Carbon::parse($filters['date']);

            return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
        }

        $from = ! empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : now()->startOfDay();
        $to = ! empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : (! empty($filters['date_from'])
                ? Carbon::parse($filters['date_from'])->endOfDay()
                : now()->endOfDay());

        return [$from, $to];
    }

    private function amount(mixed $value): float
    {
        return round((float) $value, 4);
    }
}
