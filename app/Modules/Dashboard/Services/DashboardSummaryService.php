<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DashboardSummaryService
{
    public function summary(array $filters): array
    {
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $threshold = (float) ($filters['low_stock_threshold'] ?? 3);

        $sales = Sale::query()
            ->where('status', Sale::STATUS_CONFIRMED)
            ->whereBetween('confirmed_at', [$dateFrom, $dateTo]);

        $posOrders = PosOrder::query()
            ->where('status', PosOrder::STATUS_PAID)
            ->whereBetween('paid_at', [$dateFrom, $dateTo]);

        $receivables = AccountsReceivable::query()
            ->whereIn('status', [AccountsReceivable::STATUS_PENDING, AccountsReceivable::STATUS_PARTIAL, AccountsReceivable::STATUS_OVERDUE]);

        $payables = AccountsPayable::query()
            ->whereIn('status', [AccountsPayable::STATUS_PENDING, AccountsPayable::STATUS_PARTIAL, AccountsPayable::STATUS_OVERDUE]);

        return [
            'currency' => 'USD',
            'period' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
            ],
            'sales' => [
                'confirmed_count' => (clone $sales)->count(),
                'total_base_amount' => $this->sum($sales, 'total_base_amount'),
            ],
            'pos' => [
                'paid_orders_count' => (clone $posOrders)->count(),
                'paid_base_amount' => $this->sum($posOrders, 'paid_base_amount'),
            ],
            'cash_register' => [
                'open_sessions_count' => CashRegisterSession::query()
                    ->where('status', CashRegisterSession::STATUS_OPEN)
                    ->count(),
            ],
            'inventory' => [
                'low_stock_count' => $this->lowStockQuery($threshold)->count(),
                'low_stock_threshold' => $threshold,
                'low_stock_items' => $this->lowStockItems($threshold),
            ],
            'finance' => [
                'accounts_receivable_balance_base_amount' => $this->sum($receivables, 'balance_base_amount'),
                'accounts_payable_balance_base_amount' => $this->sum($payables, 'balance_base_amount'),
                'accounts_receivable_count' => (clone $receivables)->count(),
                'accounts_payable_count' => (clone $payables)->count(),
            ],
        ];
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
        return $this->lowStockQuery($threshold)
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

    private function lowStockQuery(float $threshold): Builder
    {
        return StockBalance::query()
            ->where('quantity_available', '<=', $threshold);
    }

    private function sum(Builder $query, string $column): float
    {
        return round((float) (clone $query)->sum($column), 4);
    }
}
