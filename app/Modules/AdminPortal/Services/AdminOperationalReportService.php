<?php

namespace App\Modules\AdminPortal\Services;

use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminOperationalReportService
{
    public function summary(array $filters): array
    {
        $tenant = app(TenantManager::class)->require();
        $tenantId = $tenant->id;
        [$dateFrom, $dateTo] = $this->dateRange($filters);

        $confirmedSales = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->whereBetween('confirmed_at', [$dateFrom, $dateTo]);

        $paidOrders = DB::table('pos_orders')
            ->where('tenant_id', $tenantId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$dateFrom, $dateTo]);

        $paidCount = (clone $paidOrders)->count();
        $paidTotal = $this->sum($paidOrders, 'paid_base_amount');

        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'period' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
            ],
            'currency' => 'USD',
            'sales' => [
                'confirmed_count' => (clone $confirmedSales)->count(),
                'confirmed_base_amount' => $this->sum($confirmedSales, 'total_base_amount'),
                'pos_paid_count' => $paidCount,
                'pos_paid_base_amount' => $paidTotal,
                'average_ticket_base_amount' => $paidCount > 0 ? round($paidTotal / $paidCount, 4) : 0.0,
                'pending_pos_count' => DB::table('pos_orders')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'open')
                    ->count(),
                'pending_pos_base_amount' => $this->sum(
                    DB::table('pos_orders')
                        ->where('tenant_id', $tenantId)
                        ->where('status', 'open'),
                    'total_base_amount'
                ),
            ],
            'cash_register' => $this->cashRegisterSummary($tenantId, $dateFrom, $dateTo),
            'payment_methods' => $this->paymentMethods($tenantId, $dateFrom, $dateTo),
            'top_products' => $this->topProducts($tenantId, $dateFrom, $dateTo),
            'recent_orders' => $this->recentOrders($tenantId, $dateFrom, $dateTo),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function cashRegisterSummary(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $sessions = DB::table('cash_register_sessions')
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($dateFrom, $dateTo): void {
                $query->whereBetween('opened_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('closed_at', [$dateFrom, $dateTo])
                    ->orWhere('status', 'open');
            });

        return [
            'opened_count' => (clone $sessions)->count(),
            'open_count' => (clone $sessions)->where('status', 'open')->count(),
            'closed_count' => (clone $sessions)->where('status', 'closed')->count(),
            'expected_base_amount' => $this->sum($sessions, 'expected_base_amount'),
            'difference_base_amount' => $this->sum($sessions, 'difference_base_amount'),
            'sessions' => $this->cashRegisterSessions($tenantId, $dateFrom, $dateTo),
        ];
    }

    private function cashRegisterSessions(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        return DB::table('cash_register_sessions')
            ->leftJoin('cash_registers', 'cash_registers.id', '=', 'cash_register_sessions.cash_register_id')
            ->leftJoin('branches', 'branches.id', '=', 'cash_register_sessions.branch_id')
            ->leftJoin('users as cashiers', 'cashiers.id', '=', 'cash_register_sessions.cashier_id')
            ->where('cash_register_sessions.tenant_id', $tenantId)
            ->where(function ($query) use ($dateFrom, $dateTo): void {
                $query->whereBetween('cash_register_sessions.opened_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('cash_register_sessions.closed_at', [$dateFrom, $dateTo])
                    ->orWhere('cash_register_sessions.status', 'open');
            })
            ->orderByRaw("case when cash_register_sessions.status = 'open' then 0 else 1 end")
            ->orderByDesc('cash_register_sessions.opened_at')
            ->limit(8)
            ->get([
                'cash_register_sessions.id',
                'cash_register_sessions.status',
                'cash_register_sessions.expected_base_amount',
                'cash_register_sessions.difference_base_amount',
                'cash_register_sessions.opened_at',
                'cash_register_sessions.closed_at',
                'cash_registers.name as cash_register_name',
                'branches.name as branch_name',
                'cashiers.name as cashier_name',
            ])
            ->map(fn ($session): array => [
                'id' => $session->id,
                'status' => $session->status,
                'cash_register_name' => $session->cash_register_name ?? 'Caja sin nombre',
                'branch_name' => $session->branch_name ?? 'Sin sucursal',
                'cashier_name' => $session->cashier_name ?? 'Sin cajero',
                'expected_base_amount' => round((float) $session->expected_base_amount, 4),
                'difference_base_amount' => round((float) $session->difference_base_amount, 4),
                'opened_at' => $session->opened_at,
                'closed_at' => $session->closed_at,
            ])
            ->all();
    }

    private function paymentMethods(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        return DB::table('pos_payments')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_payments.pos_order_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'pos_payments.payment_method_id')
            ->where('pos_payments.tenant_id', $tenantId)
            ->where('pos_orders.tenant_id', $tenantId)
            ->where('pos_payments.status', 'captured')
            ->where('pos_orders.status', 'paid')
            ->whereBetween('pos_orders.paid_at', [$dateFrom, $dateTo])
            ->groupBy('pos_payments.method', 'pos_payments.currency', 'payment_methods.name')
            ->orderByDesc(DB::raw('SUM(pos_payments.amount_base)'))
            ->limit(8)
            ->get([
                'pos_payments.method',
                'pos_payments.currency',
                'payment_methods.name as payment_method_name',
                DB::raw('COUNT(*) as payments_count'),
                DB::raw('SUM(pos_payments.amount_base) as amount_base'),
                DB::raw('SUM(pos_payments.amount_local) as amount_local'),
            ])
            ->map(fn ($method): array => [
                'method' => $method->method,
                'currency' => $method->currency,
                'name' => $method->payment_method_name ?? $this->paymentMethodLabel($method->method, $method->currency),
                'payments_count' => (int) $method->payments_count,
                'amount_base' => round((float) $method->amount_base, 4),
                'amount_local' => round((float) $method->amount_local, 4),
            ])
            ->all();
    }

    private function topProducts(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sale_items.tenant_id', $tenantId)
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.status', 'confirmed')
            ->whereBetween('sales.confirmed_at', [$dateFrom, $dateTo])
            ->groupBy('sale_items.product_id', 'products.name', 'products.sku')
            ->orderByDesc(DB::raw('SUM(sale_items.base_total_amount)'))
            ->limit(8)
            ->get([
                'sale_items.product_id',
                'products.name as product_name',
                'products.sku as product_sku',
                DB::raw('SUM(sale_items.quantity) as quantity'),
                DB::raw('SUM(sale_items.base_total_amount) as total_base_amount'),
            ])
            ->map(fn ($product): array => [
                'product_id' => $product->product_id,
                'product_name' => $product->product_name ?? 'Producto eliminado',
                'product_sku' => $product->product_sku ?? '',
                'quantity' => round((float) $product->quantity, 4),
                'total_base_amount' => round((float) $product->total_base_amount, 4),
            ])
            ->all();
    }

    private function recentOrders(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        return DB::table('pos_orders')
            ->leftJoin('cash_register_sessions', 'cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
            ->leftJoin('cash_registers', 'cash_registers.id', '=', 'cash_register_sessions.cash_register_id')
            ->where('pos_orders.tenant_id', $tenantId)
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('cash_register_sessions.id')
                    ->orWhere('cash_register_sessions.tenant_id', $tenantId);
            })
            ->where(function ($query) use ($dateFrom, $dateTo): void {
                $query->whereBetween('pos_orders.paid_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('pos_orders.opened_at', [$dateFrom, $dateTo]);
            })
            ->orderByDesc('pos_orders.id')
            ->limit(10)
            ->get([
                'pos_orders.id',
                'pos_orders.status',
                'pos_orders.customer_name',
                'pos_orders.total_base_amount',
                'pos_orders.paid_base_amount',
                'pos_orders.opened_at',
                'pos_orders.paid_at',
                'cash_registers.name as cash_register_name',
            ])
            ->map(fn ($order): array => [
                'id' => $order->id,
                'status' => $order->status,
                'customer_name' => $order->customer_name ?: 'Consumidor final',
                'cash_register_name' => $order->cash_register_name ?? 'Sin caja',
                'total_base_amount' => round((float) $order->total_base_amount, 4),
                'paid_base_amount' => round((float) $order->paid_base_amount, 4),
                'balance_base_amount' => round(max((float) $order->total_base_amount - (float) $order->paid_base_amount, 0), 4),
                'opened_at' => $order->opened_at,
                'paid_at' => $order->paid_at,
            ])
            ->all();
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

    private function paymentMethodLabel(?string $method, ?string $currency): string
    {
        $label = [
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'mobile_payment' => 'Pago movil',
            'transfer' => 'Transferencia',
            'zelle' => 'Zelle',
            'external_financing' => 'Financiadora',
            'other' => 'Otro',
        ][$method] ?? 'Metodo';

        return trim($label.' '.($currency ?: ''));
    }

    private function sum($query, string $column): float
    {
        return round((float) (clone $query)->sum($column), 4);
    }
}
