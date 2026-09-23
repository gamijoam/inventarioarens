<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sales\Models\Sale;
use App\Support\Tenancy\TenantManager;
use App\Support\Time\BusinessDateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardSummaryService
{
    public function summary(array $filters): array
    {
        [$dateFrom, $dateTo] = self::resolveDateRange($filters);
        $threshold = (float) ($filters['low_stock_threshold'] ?? 3);

        $metrics = $this->aggregatedMetrics($dateFrom, $dateTo, $threshold);

        $lowStockCount = (int) ($metrics['low_stock_count'] ?? 0);
        $tz = BusinessDateRange::timezone();

        $salesTotal = (float) ($metrics['sales_total'] ?? 0);
        $returnedBase = (float) ($metrics['returned_base_amount'] ?? 0);
        $netSales = $salesTotal - $returnedBase;

        return [
            'currency' => 'USD',
            'period' => [
                'from' => $dateFrom->copy()->timezone($tz)->toDateString(),
                'to' => $dateTo->copy()->timezone($tz)->toDateString(),
            ],
            'sales' => [
                'confirmed_count' => (int) ($metrics['sales_count'] ?? 0),
                'total_base_amount' => round($salesTotal, 4),
                'returned_base_amount' => round($returnedBase, 4),
                'net_base_amount' => round($netSales, 4),
            ],
            'returns' => [
                'processed_count' => (int) ($metrics['returns_count'] ?? 0),
                'processed_base_amount' => round($returnedBase, 4),
            ],
            'pos' => [
                'paid_orders_count' => (int) ($metrics['pos_count'] ?? 0),
                'paid_base_amount' => round((float) ($metrics['pos_total'] ?? 0), 4),
            ],
            'cash_register' => [
                'open_sessions_count' => (int) ($metrics['cash_open_sessions'] ?? 0),
            ],
            'inventory' => [
                'low_stock_count' => $lowStockCount,
                'low_stock_threshold' => $threshold,
                'low_stock_items' => $this->lowStockItems($threshold),
                'stock_cost_value' => round((float) ($metrics['stock_cost_value'] ?? 0), 4),
                'stock_retail_value' => round((float) ($metrics['stock_retail_value'] ?? 0), 4),
                'stock_total_units' => round((float) ($metrics['stock_total_units'] ?? 0), 4),
            ],
            'finance' => [
                'accounts_receivable_balance_base_amount' => round((float) ($metrics['receivable_balance'] ?? 0), 4),
                'accounts_payable_balance_base_amount' => round((float) ($metrics['payable_balance'] ?? 0), 4),
                'accounts_receivable_count' => (int) ($metrics['receivable_count'] ?? 0),
                'accounts_payable_count' => (int) ($metrics['payable_count'] ?? 0),
            ],
        ];
    }

    /**
     * Ejecuta una sola query SQL con UNION ALL para obtener todas las
     * metricas agregadas (counts, sums, balances) en un solo round-trip.
     * Reduce 10 queries a 1.
     */
    private function aggregatedMetrics(Carbon $dateFrom, Carbon $dateTo, float $threshold): array
    {
        $tenantId = (int) app(TenantManager::class)->require()->id;
        $dateFromStr = $dateFrom->toDateTimeString();
        $dateToStr = $dateTo->toDateTimeString();
        $thresholdStr = (string) $threshold;
        $salesConfirmed = Sale::STATUS_CONFIRMED;
        $posPaid = PosOrder::STATUS_PAID;
        $cashOpen = CashRegisterSession::STATUS_OPEN;
        $arActive = "'pending', 'partial', 'overdue'";
        $apActive = "'pending', 'partial', 'overdue'";

        $sql = "
            select 'sales_count' as metric, cast(count(*) as text) as val_num from sales where tenant_id = ? and status = ? and confirmed_at between ? and ?
            union all
            select 'sales_total' as metric, cast(coalesce(sum(total_base_amount), 0) as text) as val_num from sales where tenant_id = ? and status = ? and confirmed_at between ? and ?
            union all
            select 'pos_count' as metric, cast(count(*) as text) as val_num from pos_orders where tenant_id = ? and status = ? and paid_at between ? and ?
            union all
            select 'pos_total' as metric, cast(coalesce(sum(paid_base_amount), 0) as text) as val_num from pos_orders where tenant_id = ? and status = ? and paid_at between ? and ?
            union all
            select 'cash_open_sessions' as metric, cast(count(*) as text) as val_num from cash_register_sessions where tenant_id = ? and status = ?
            union all
            select 'low_stock_count' as metric, cast(count(*) as text) as val_num from stock_balances where tenant_id = ? and quantity_available <= ?
            union all
            select 'receivable_count' as metric, cast(count(*) as text) as val_num from accounts_receivables where tenant_id = ? and status in ({$arActive})
            union all
            select 'receivable_balance' as metric, cast(coalesce(sum(balance_base_amount), 0) as text) as val_num from accounts_receivables where tenant_id = ? and status in ({$arActive})
            union all
            select 'payable_count' as metric, cast(count(*) as text) as val_num from accounts_payables where tenant_id = ? and status in ({$apActive})
            union all
            select 'payable_balance' as metric, cast(coalesce(sum(balance_base_amount), 0) as text) as val_num from accounts_payables where tenant_id = ? and status in ({$apActive})
            union all
            select 'stock_cost_value' as metric, cast(coalesce(sum(sb.quantity_available * coalesce(p.last_purchase_cost, p.average_cost, 0)), 0) as text) as val_num from stock_balances sb join products p on p.id = sb.product_id and p.tenant_id = sb.tenant_id where sb.tenant_id = ?
            union all
            select 'stock_retail_value' as metric, cast(coalesce(sum(sb.quantity_available * coalesce(p.base_price, 0)), 0) as text) as val_num from stock_balances sb join products p on p.id = sb.product_id and p.tenant_id = sb.tenant_id where sb.tenant_id = ?
            union all
            select 'stock_total_units' as metric, cast(coalesce(sum(sb.quantity_available), 0) as text) as val_num from stock_balances sb where sb.tenant_id = ?
            union all
            select 'returns_count' as metric, cast(count(*) as text) as val_num from sales_returns sr where sr.tenant_id = ? and sr.status = 'processed' and sr.processed_at between ? and ?
            union all
            select 'returned_base_amount' as metric, cast(coalesce(sum(case when si.quantity > 0 then si.base_total_amount / si.quantity * sri.quantity else 0 end), 0) as text) as val_num
                from sales_returns sr
                join sales_return_items sri on sri.sales_return_id = sr.id and sri.tenant_id = sr.tenant_id
                join sale_items si on si.id = sri.sale_item_id and si.tenant_id = sri.tenant_id
                where sr.tenant_id = ? and sr.status = 'processed' and sr.processed_at between ? and ?
            union all
            select 'returned_cost_base_amount' as metric, cast(coalesce(sum(case when si.quantity > 0 then coalesce(nullif(si.base_unit_cost, 0), p.last_purchase_cost, p.average_cost, 0) * sri.quantity else 0 end), 0) as text) as val_num
                from sales_returns sr
                join sales_return_items sri on sri.sales_return_id = sr.id and sri.tenant_id = sr.tenant_id
                join sale_items si on si.id = sri.sale_item_id and si.tenant_id = sri.tenant_id
                left join products p on p.id = si.product_id and p.tenant_id = si.tenant_id
                where sr.tenant_id = ? and sr.status = 'processed' and sr.processed_at between ? and ?
        ";

        $bindings = [
            $tenantId, $salesConfirmed, $dateFromStr, $dateToStr,
            $tenantId, $salesConfirmed, $dateFromStr, $dateToStr,
            $tenantId, $posPaid, $dateFromStr, $dateToStr,
            $tenantId, $posPaid, $dateFromStr, $dateToStr,
            $tenantId, $cashOpen,
            $tenantId, $thresholdStr,
            $tenantId,
            $tenantId,
            $tenantId,
            $tenantId,
            $tenantId,
            $tenantId,
            $tenantId,
            $tenantId,
            $dateFromStr,
            $dateToStr,
            $tenantId,
            $dateFromStr,
            $dateToStr,
            $tenantId,
            $dateFromStr,
            $dateToStr,
        ];

        $rows = DB::select($sql, $bindings);

        $metrics = [];
        foreach ($rows as $row) {
            $val = $row->val_num === null ? null : (float) $row->val_num;
            $metrics[$row->metric] = $val;
        }

        return $metrics;
    }

    public static function resolveDateRange(array $filters): array
    {
        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null)) {
            return [
                BusinessDateRange::startOfDay($filters['date_from']),
                BusinessDateRange::endOfDay($filters['date_to']),
            ];
        }

        $tz = BusinessDateRange::timezone();
        $now = now($tz);

        return match ($filters['period'] ?? 'today') {
            'week' => [
                $now->copy()->startOfWeek()->startOfDay()->utc(),
                $now->copy()->endOfWeek()->endOfDay()->utc(),
            ],
            'month' => [
                $now->copy()->startOfMonth()->startOfDay()->utc(),
                $now->copy()->endOfMonth()->endOfDay()->utc(),
            ],
            default => [
                BusinessDateRange::startOfDay($now),
                BusinessDateRange::endOfDay($now),
            ],
        };
    }

    private function lowStockItems(float $threshold): array
    {
        return StockBalance::query()
            ->where('quantity_available', '<=', $threshold)
            ->select(['id', 'warehouse_id', 'product_id', 'quantity_available'])
            ->with([
                'product:id,name,sku',
                'warehouse:id,name',
            ])
            ->orderBy('quantity_available')
            ->orderBy('product_id')
            ->limit(5)
            ->get()
            ->map(fn (StockBalance $balance): array => [
                'product_id' => $balance->product_id,
                'product_name' => $balance->product?->name,
                'sku' => $balance->product?->sku,
                'warehouse_id' => $balance->warehouse_id,
                'warehouse_name' => $balance->warehouse?->name,
                'quantity_available' => (float) $balance->quantity_available,
            ])
            ->all();
    }
}
