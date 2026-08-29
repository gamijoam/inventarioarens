<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consolida el dashboard de todas las empresas hijas de un grupo en una sola
 * consulta SQL agregada por tenant_id. No itera empresas en PHP: el numero de
 * queries es fijo (listado del grupo + agregacion) sin importar cuantas
 * sucursales existan.
 */
class OrganizationDashboardService
{
    public function summary(array $filters, Tenant $group): array
    {
        [$dateFrom, $dateTo] = DashboardSummaryService::resolveDateRange($filters);
        $threshold = (float) ($filters['low_stock_threshold'] ?? 3);

        $companies = $group->spinoffs()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $tenantIds = $companies->pluck('id')->all();

        if ($tenantIds === []) {
            return [
                'scope' => 'organization',
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                ],
                'period' => [
                    'from' => $dateFrom->toDateString(),
                    'to' => $dateTo->toDateString(),
                ],
                'totals' => $this->emptyTotals(),
                'companies' => [],
            ];
        }

        $metrics = $this->aggregatedMetrics($tenantIds, $dateFrom, $dateTo, $threshold);

        $companiesPayload = $companies->map(function (Tenant $company) use ($metrics): array {
            $row = $metrics[$company->id] ?? $this->emptyRow();

            return [
                'tenant_id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'sales' => [
                    'confirmed_count' => (int) $row['sales_count'],
                    'total_base_amount' => round((float) $row['sales_total'], 4),
                ],
                'pos' => [
                    'paid_orders_count' => (int) $row['pos_count'],
                    'paid_base_amount' => round((float) $row['pos_total'], 4),
                ],
                'cash_register' => [
                    'open_sessions_count' => (int) $row['open_sessions'],
                ],
                'inventory' => [
                    'low_stock_count' => (int) $row['low_stock_count'],
                ],
                'finance' => [
                    'accounts_receivable_balance_base_amount' => round((float) $row['receivable_balance'], 4),
                    'accounts_payable_balance_base_amount' => round((float) $row['payable_balance'], 4),
                ],
            ];
        })->values()->all();

        return [
            'scope' => 'organization',
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
            ],
            'period' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
            ],
            'totals' => $this->computeTotals($companiesPayload),
            'companies' => $companiesPayload,
        ];
    }

    private function aggregatedMetrics(array $tenantIds, Carbon $dateFrom, Carbon $dateTo, float $threshold): array
    {
        $salesConfirmed = Sale::STATUS_CONFIRMED;
        $posPaid = PosOrder::STATUS_PAID;
        $cashOpen = CashRegisterSession::STATUS_OPEN;
        $arActive = "'pending', 'partial', 'overdue'";
        $apActive = "'pending', 'partial', 'overdue'";
        $dateFromStr = $dateFrom->toDateTimeString();
        $dateToStr = $dateTo->toDateTimeString();
        $thresholdStr = (string) $threshold;
        $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));

        $sql = "
            select
                t.id as tenant_id,
                coalesce(s.sales_count, 0) as sales_count,
                coalesce(s.sales_total, 0) as sales_total,
                coalesce(p.pos_count, 0) as pos_count,
                coalesce(p.pos_total, 0) as pos_total,
                coalesce(c.open_sessions, 0) as open_sessions,
                coalesce(ar.receivable_count, 0) as receivable_count,
                coalesce(ar.receivable_balance, 0) as receivable_balance,
                coalesce(ap.payable_count, 0) as payable_count,
                coalesce(ap.payable_balance, 0) as payable_balance,
                coalesce(sb.low_stock_count, 0) as low_stock_count
            from tenants t
            left join (
                select tenant_id, count(*) as sales_count, coalesce(sum(total_base_amount), 0) as sales_total
                from sales
                where tenant_id in ({$placeholders}) and status = ? and confirmed_at between ? and ?
                group by tenant_id
            ) s on s.tenant_id = t.id
            left join (
                select tenant_id, count(*) as pos_count, coalesce(sum(paid_base_amount), 0) as pos_total
                from pos_orders
                where tenant_id in ({$placeholders}) and status = ? and paid_at between ? and ?
                group by tenant_id
            ) p on p.tenant_id = t.id
            left join (
                select tenant_id, count(*) as open_sessions
                from cash_register_sessions
                where tenant_id in ({$placeholders}) and status = ?
                group by tenant_id
            ) c on c.tenant_id = t.id
            left join (
                select tenant_id, count(*) as receivable_count, coalesce(sum(balance_base_amount), 0) as receivable_balance
                from accounts_receivables
                where tenant_id in ({$placeholders}) and status in ({$arActive})
                group by tenant_id
            ) ar on ar.tenant_id = t.id
            left join (
                select tenant_id, count(*) as payable_count, coalesce(sum(balance_base_amount), 0) as payable_balance
                from accounts_payables
                where tenant_id in ({$placeholders}) and status in ({$apActive})
                group by tenant_id
            ) ap on ap.tenant_id = t.id
            left join (
                select tenant_id, count(*) as low_stock_count
                from stock_balances
                where tenant_id in ({$placeholders}) and quantity_available <= ?
                group by tenant_id
            ) sb on sb.tenant_id = t.id
            where t.id in ({$placeholders})
            order by sales_total desc nulls last
        ";

        $bindings = [];
        foreach ($tenantIds as $id) {
            $bindings[] = $id;
        }
        $bindings[] = $salesConfirmed;
        $bindings[] = $dateFromStr;
        $bindings[] = $dateToStr;
        foreach ($tenantIds as $id) {
            $bindings[] = $id;
        }
        $bindings[] = $posPaid;
        $bindings[] = $dateFromStr;
        $bindings[] = $dateToStr;
        foreach ($tenantIds as $id) {
            $bindings[] = $id;
        }
        $bindings[] = $cashOpen;
        foreach ($tenantIds as $id) {
            $bindings[] = $id;
        }
        foreach ($tenantIds as $id) {
            $bindings[] = $id;
        }
        foreach ($tenantIds as $id) {
            $bindings[] = $id;
        }
        $bindings[] = $thresholdStr;
        foreach ($tenantIds as $id) {
            $bindings[] = $id;
        }

        $rows = DB::select($sql, $bindings);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->tenant_id] = [
                'sales_count' => (int) $row->sales_count,
                'sales_total' => (float) $row->sales_total,
                'pos_count' => (int) $row->pos_count,
                'pos_total' => (float) $row->pos_total,
                'open_sessions' => (int) $row->open_sessions,
                'receivable_count' => (int) $row->receivable_count,
                'receivable_balance' => (float) $row->receivable_balance,
                'payable_count' => (int) $row->payable_count,
                'payable_balance' => (float) $row->payable_balance,
                'low_stock_count' => (int) $row->low_stock_count,
            ];
        }

        return $result;
    }

    private function computeTotals(array $companies): array
    {
        $totals = $this->emptyTotals();

        foreach ($companies as $company) {
            $totals['sales_count'] += $company['sales']['confirmed_count'];
            $totals['sales_total_base_amount'] += $company['sales']['total_base_amount'];
            $totals['pos_orders_count'] += $company['pos']['paid_orders_count'];
            $totals['pos_paid_base_amount'] += $company['pos']['paid_base_amount'];
            $totals['open_cash_sessions'] += $company['cash_register']['open_sessions_count'];
            $totals['receivable_balance_base_amount'] += $company['finance']['accounts_receivable_balance_base_amount'];
            $totals['payable_balance_base_amount'] += $company['finance']['accounts_payable_balance_base_amount'];
            $totals['low_stock_count'] += $company['inventory']['low_stock_count'];
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'sales_count' => 0,
            'sales_total_base_amount' => 0,
            'pos_orders_count' => 0,
            'pos_paid_base_amount' => 0,
            'open_cash_sessions' => 0,
            'receivable_balance_base_amount' => 0,
            'payable_balance_base_amount' => 0,
            'low_stock_count' => 0,
        ];
    }

    private function emptyRow(): array
    {
        return [
            'sales_count' => 0,
            'sales_total' => 0,
            'pos_count' => 0,
            'pos_total' => 0,
            'open_sessions' => 0,
            'receivable_count' => 0,
            'receivable_balance' => 0,
            'payable_count' => 0,
            'payable_balance' => 0,
            'low_stock_count' => 0,
        ];
    }
}
