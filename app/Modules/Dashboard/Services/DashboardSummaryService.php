<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardSummaryService
{
    public function summary(array $filters): array
    {
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $threshold = (float) ($filters['low_stock_threshold'] ?? 3);

        $metrics = $this->aggregatedMetrics($dateFrom, $dateTo, $threshold);

        $lowStockCount = (int) ($metrics['low_stock_count'] ?? 0);

        return [
            'currency' => 'USD',
            'period' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
            ],
            'sales' => [
                'confirmed_count' => (int) ($metrics['sales_count'] ?? 0),
                'total_base_amount' => round((float) ($metrics['sales_total'] ?? 0), 4),
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
     * Reduce 7 queries a 1.
     */
    private function aggregatedMetrics(Carbon $dateFrom, Carbon $dateTo, float $threshold): array
    {
        $tenantId = (int) app(\App\Support\Tenancy\TenantManager::class)->require()->id;
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
        ];

        $rows = DB::select($sql, $bindings);

        $metrics = [];
        foreach ($rows as $row) {
            $val = $row->val_num === null ? null : (float) $row->val_num;
            $metrics[$row->metric] = $val;
        }

        return $metrics;
    }

    private function dateRange(array $filters): array
    {
        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null)) {
            return [
                Carbon::parse($filters['date_from'])->startOfDay(),
                Carbon::parse($filters['date_to'])->endOfDay(),
            ];
        }

        $now = now();

        return match ($filters['period'] ?? 'today') {
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
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
