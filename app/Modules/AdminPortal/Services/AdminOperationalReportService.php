<?php

namespace App\Modules\AdminPortal\Services;

use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Query\Builder;
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

        $paidOrders = $this->posOrdersQuery($tenantId, $dateFrom, $dateTo, $filters, 'paid');
        $paidCount = (clone $paidOrders)->count();
        $paidTotal = $this->sum($paidOrders, 'pos_orders.paid_base_amount');
        $pendingOrders = $this->pendingOrdersQuery($tenantId, $filters);

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
            'filters' => [
                'selected' => $this->selectedFilters($filters),
                'options' => $this->filterOptions($tenantId),
            ],
            'sales' => [
                'confirmed_count' => (clone $confirmedSales)->count(),
                'confirmed_base_amount' => $this->sum($confirmedSales, 'total_base_amount'),
                'pos_paid_count' => $paidCount,
                'pos_paid_base_amount' => $paidTotal,
                'average_ticket_base_amount' => $paidCount > 0 ? round($paidTotal / $paidCount, 4) : 0.0,
                'pending_pos_count' => (clone $pendingOrders)->count(),
                'pending_pos_base_amount' => $this->sum($pendingOrders, 'pos_orders.total_base_amount'),
            ],
            'cash_register' => $this->cashRegisterSummary($tenantId, $dateFrom, $dateTo, $filters),
            'payment_methods' => $this->paymentMethods($tenantId, $dateFrom, $dateTo, $filters),
            'top_products' => $this->topProducts($tenantId, $dateFrom, $dateTo, $filters),
            'recent_orders' => $this->recentOrders($tenantId, $dateFrom, $dateTo, $filters),
            'generated_at' => now()->toISOString(),
        ];
    }

    public function export(array $filters, string $section): array
    {
        $tenant = app(TenantManager::class)->require();
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $stamp = now()->format('Ymd-His');

        return match ($section) {
            'payment_methods' => [
                'filename' => "reporte-metodos-pago-{$tenant->slug}-{$stamp}.csv",
                'headers' => ['Metodo', 'Moneda', 'Pagos', 'Total USD', 'Total Bs'],
                'rows' => array_map(fn (array $row): array => [
                    $row['name'],
                    $row['currency'],
                    $row['payments_count'],
                    $row['amount_base'],
                    $row['amount_local'],
                ], $this->paymentMethods($tenant->id, $dateFrom, $dateTo, $filters, 500)),
            ],
            'top_products' => [
                'filename' => "reporte-productos-vendidos-{$tenant->slug}-{$stamp}.csv",
                'headers' => ['Producto', 'SKU', 'Cantidad', 'Total USD'],
                'rows' => array_map(fn (array $row): array => [
                    $row['product_name'],
                    $row['product_sku'],
                    $row['quantity'],
                    $row['total_base_amount'],
                ], $this->topProducts($tenant->id, $dateFrom, $dateTo, $filters, 500)),
            ],
            'cash_sessions' => [
                'filename' => "reporte-cajas-{$tenant->slug}-{$stamp}.csv",
                'headers' => ['Caja', 'Sucursal', 'Cajero', 'Estado', 'Esperado USD', 'Diferencia USD', 'Apertura', 'Cierre'],
                'rows' => array_map(fn (array $row): array => [
                    $row['cash_register_name'],
                    $row['branch_name'],
                    $row['cashier_name'],
                    $this->cashSessionStatusLabel($row['status']),
                    $row['expected_base_amount'],
                    $row['difference_base_amount'],
                    $row['opened_at'],
                    $row['closed_at'],
                ], $this->cashRegisterSessions($tenant->id, $dateFrom, $dateTo, $filters, 500)),
            ],
            default => [
                'filename' => "reporte-ordenes-pos-{$tenant->slug}-{$stamp}.csv",
                'headers' => ['Orden', 'Cliente', 'Caja', 'Cajero', 'Estado', 'Total USD', 'Pagado USD', 'Saldo USD', 'Apertura', 'Pago'],
                'rows' => array_map(fn (array $row): array => [
                    $row['id'],
                    $row['customer_name'],
                    $row['cash_register_name'],
                    $row['cashier_name'],
                    $this->posOrderStatusLabel($row['status']),
                    $row['total_base_amount'],
                    $row['paid_base_amount'],
                    $row['balance_base_amount'],
                    $row['opened_at'],
                    $row['paid_at'],
                ], $this->recentOrders($tenant->id, $dateFrom, $dateTo, $filters, 1000)),
            ],
        };
    }

    private function cashRegisterSummary(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters): array
    {
        $sessions = DB::table('cash_register_sessions')
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($dateFrom, $dateTo): void {
                $query->whereBetween('opened_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('closed_at', [$dateFrom, $dateTo])
                    ->orWhere('status', 'open');
            });
        $this->applySessionFilters($sessions, $filters);

        return [
            'opened_count' => (clone $sessions)->count(),
            'open_count' => (clone $sessions)->where('status', 'open')->count(),
            'closed_count' => (clone $sessions)->where('status', 'closed')->count(),
            'expected_base_amount' => $this->sum($sessions, 'expected_base_amount'),
            'difference_base_amount' => $this->sum($sessions, 'difference_base_amount'),
            'sessions' => $this->cashRegisterSessions($tenantId, $dateFrom, $dateTo, $filters),
        ];
    }

    private function cashRegisterSessions(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters, int $limit = 8): array
    {
        $query = DB::table('cash_register_sessions')
            ->leftJoin('cash_registers', 'cash_registers.id', '=', 'cash_register_sessions.cash_register_id')
            ->leftJoin('branches', 'branches.id', '=', 'cash_register_sessions.branch_id')
            ->leftJoin('users as cashiers', 'cashiers.id', '=', 'cash_register_sessions.cashier_id')
            ->where('cash_register_sessions.tenant_id', $tenantId)
            ->where(function ($query) use ($dateFrom, $dateTo): void {
                $query->whereBetween('cash_register_sessions.opened_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('cash_register_sessions.closed_at', [$dateFrom, $dateTo])
                    ->orWhere('cash_register_sessions.status', 'open');
            });
        $this->applySessionFilters($query, $filters, 'cash_register_sessions');

        return $query
            ->orderByRaw("case when cash_register_sessions.status = 'open' then 0 else 1 end")
            ->orderByDesc('cash_register_sessions.opened_at')
            ->limit($limit)
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

    private function paymentMethods(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters, int $limit = 8): array
    {
        $query = DB::table('pos_payments')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_payments.pos_order_id')
            ->leftJoin('cash_register_sessions', 'cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'pos_payments.payment_method_id')
            ->where('pos_payments.tenant_id', $tenantId)
            ->where('pos_orders.tenant_id', $tenantId)
            ->where('pos_payments.status', 'captured')
            ->where('pos_orders.status', 'paid')
            ->whereBetween('pos_orders.paid_at', [$dateFrom, $dateTo]);
        $this->applyOrderFilters($query, $filters);

        return $query
            ->groupBy('pos_payments.method', 'pos_payments.currency', 'payment_methods.name')
            ->orderByDesc(DB::raw('SUM(pos_payments.amount_base)'))
            ->limit($limit)
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

    private function topProducts(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters, int $limit = 8): array
    {
        $query = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('pos_orders', function ($join): void {
                $join->on('pos_orders.sale_id', '=', 'sales.id')
                    ->on('pos_orders.tenant_id', '=', 'sales.tenant_id');
            })
            ->leftJoin('cash_register_sessions', 'cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sale_items.tenant_id', $tenantId)
            ->where('sales.tenant_id', $tenantId)
            ->where('pos_orders.tenant_id', $tenantId)
            ->where('sales.status', 'confirmed')
            ->where('pos_orders.status', 'paid')
            ->whereBetween('pos_orders.paid_at', [$dateFrom, $dateTo]);
        $this->applyOrderFilters($query, $filters);

        return $query
            ->groupBy('sale_items.product_id', 'products.name', 'products.sku')
            ->orderByDesc(DB::raw('SUM(sale_items.base_total_amount)'))
            ->limit($limit)
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

    private function recentOrders(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters, int $limit = 10): array
    {
        $query = DB::table('pos_orders')
            ->leftJoin('cash_register_sessions', 'cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
            ->leftJoin('cash_registers', 'cash_registers.id', '=', 'cash_register_sessions.cash_register_id')
            ->leftJoin('users as cashiers', 'cashiers.id', '=', 'pos_orders.cashier_id')
            ->where('pos_orders.tenant_id', $tenantId)
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('cash_register_sessions.id')
                    ->orWhere('cash_register_sessions.tenant_id', $tenantId);
            })
            ->where(function ($query) use ($dateFrom, $dateTo): void {
                $query->whereBetween('pos_orders.paid_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('pos_orders.opened_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('pos_orders.closed_at', [$dateFrom, $dateTo]);
            });
        $this->applyOrderFilters($query, $filters);

        return $query
            ->orderByDesc('pos_orders.id')
            ->limit($limit)
            ->get([
                'pos_orders.id',
                'pos_orders.status',
                'pos_orders.customer_name',
                'pos_orders.total_base_amount',
                'pos_orders.paid_base_amount',
                'pos_orders.opened_at',
                'pos_orders.paid_at',
                'cash_registers.name as cash_register_name',
                'cashiers.name as cashier_name',
            ])
            ->map(fn ($order): array => [
                'id' => $order->id,
                'status' => $order->status,
                'customer_name' => $order->customer_name ?: 'Consumidor final',
                'cash_register_name' => $order->cash_register_name ?? 'Sin caja',
                'cashier_name' => $order->cashier_name ?? 'Sin cajero',
                'total_base_amount' => round((float) $order->total_base_amount, 4),
                'paid_base_amount' => round((float) $order->paid_base_amount, 4),
                'balance_base_amount' => round(max((float) $order->total_base_amount - (float) $order->paid_base_amount, 0), 4),
                'opened_at' => $order->opened_at,
                'paid_at' => $order->paid_at,
            ])
            ->all();
    }

    private function posOrdersQuery(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters, string $status): Builder
    {
        $query = DB::table('pos_orders')
            ->leftJoin('cash_register_sessions', 'cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
            ->where('pos_orders.tenant_id', $tenantId)
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('cash_register_sessions.id')
                    ->orWhere('cash_register_sessions.tenant_id', $tenantId);
            })
            ->where('pos_orders.status', $status);

        if ($status === 'paid') {
            $query->whereBetween('pos_orders.paid_at', [$dateFrom, $dateTo]);
        } else {
            $query->whereBetween('pos_orders.opened_at', [$dateFrom, $dateTo]);
        }

        $this->applyOrderFilters($query, $filters);

        return $query;
    }

    private function pendingOrdersQuery(int $tenantId, array $filters): Builder
    {
        $query = DB::table('pos_orders')
            ->leftJoin('cash_register_sessions', 'cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
            ->where('pos_orders.tenant_id', $tenantId)
            ->where('pos_orders.status', 'open');
        $this->applyOrderFilters($query, $filters, true);

        if (($filters['status'] ?? 'all') !== 'all' && ($filters['status'] ?? null) !== 'open') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function filterOptions(int $tenantId): array
    {
        $cashierIds = DB::table('cash_register_sessions')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('cashier_id')
            ->pluck('cashier_id')
            ->merge(
                DB::table('pos_orders')
                    ->where('tenant_id', $tenantId)
                    ->whereNotNull('cashier_id')
                    ->pluck('cashier_id')
            )
            ->unique()
            ->values();

        return [
            'branches' => DB::table('branches')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn ($branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                ])
                ->all(),
            'cash_registers' => DB::table('cash_registers')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'code'])
                ->map(fn ($register): array => [
                    'id' => $register->id,
                    'branch_id' => $register->branch_id,
                    'name' => $register->name,
                    'code' => $register->code,
                ])
                ->all(),
            'cashiers' => DB::table('users')
                ->when($cashierIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $cashierIds))
                ->when($cashierIds->isEmpty(), fn ($query) => $query->whereRaw('1 = 0'))
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn ($cashier): array => [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'email' => $cashier->email,
                ])
                ->all(),
        ];
    }

    private function applyOrderFilters(Builder $query, array $filters, bool $ignoreStatus = false): void
    {
        $this->applySessionFilters($query, $filters, 'cash_register_sessions');

        if ($filters['cashier_id'] ?? null) {
            $query->where('pos_orders.cashier_id', $filters['cashier_id']);
        }

        if (! $ignoreStatus && ($filters['status'] ?? 'all') !== 'all') {
            $query->where('pos_orders.status', $filters['status']);
        }
    }

    private function applySessionFilters(Builder $query, array $filters, string $table = 'cash_register_sessions'): void
    {
        if ($filters['branch_id'] ?? null) {
            $query->where("{$table}.branch_id", $filters['branch_id']);
        }

        if ($filters['cash_register_id'] ?? null) {
            $query->where("{$table}.cash_register_id", $filters['cash_register_id']);
        }

        if ($filters['cashier_id'] ?? null) {
            $query->where("{$table}.cashier_id", $filters['cashier_id']);
        }
    }

    private function selectedFilters(array $filters): array
    {
        return [
            'branch_id' => $filters['branch_id'] ?? null,
            'cash_register_id' => $filters['cash_register_id'] ?? null,
            'cashier_id' => $filters['cashier_id'] ?? null,
            'status' => $filters['status'] ?? 'all',
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

    private function posOrderStatusLabel(string $status): string
    {
        return [
            'paid' => 'Pagada',
            'open' => 'Pendiente',
            'cancelled' => 'Cancelada',
        ][$status] ?? $status;
    }

    private function cashSessionStatusLabel(string $status): string
    {
        return [
            'open' => 'Abierta',
            'closed' => 'Cerrada',
        ][$status] ?? $status;
    }

    private function sum($query, string $column): float
    {
        return round((float) (clone $query)->sum($column), 4);
    }
}
