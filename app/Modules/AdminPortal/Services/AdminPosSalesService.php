<?php

namespace App\Modules\AdminPortal\Services;

use App\Modules\POS\Models\PosOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminPosSalesService
{
    public function index(array $filters): array
    {
        $tenant = app(TenantManager::class)->require();
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $limit = max(10, min((int) ($filters['limit'] ?? 25), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = $this->baseOrdersQuery($tenant->id, $dateFrom, $dateTo, $filters);
        $total = (clone $query)->count();
        $orders = (clone $query)
            ->orderByDesc('pos_orders.id')
            ->forPage($page, $limit)
            ->get($this->orderColumns())
            ->map(fn ($order): array => $this->mapOrder($order))
            ->all();

        $summaryQuery = $this->baseOrdersQuery($tenant->id, $dateFrom, $dateTo, $filters);

        $totalBaseAmount = $this->sum($summaryQuery, 'pos_orders.total_base_amount');
        $paidBaseAmount = $this->sum($summaryQuery, 'pos_orders.paid_base_amount');

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
            'filters' => [
                'selected' => $this->selectedFilters($filters),
                'options' => $this->filterOptions($tenant->id),
            ],
            'summary' => [
                'orders_count' => $total,
                'paid_count' => (clone $summaryQuery)->where('pos_orders.status', PosOrder::STATUS_PAID)->count(),
                'open_count' => (clone $summaryQuery)->where('pos_orders.status', PosOrder::STATUS_OPEN)->count(),
                'cancelled_count' => (clone $summaryQuery)->where('pos_orders.status', PosOrder::STATUS_CANCELLED)->count(),
                'total_base_amount' => $totalBaseAmount,
                'paid_base_amount' => $paidBaseAmount,
                'average_ticket_base_amount' => $total > 0 ? round($totalBaseAmount / $total, 4) : 0.0,
            ],
            'analytics' => [
                'by_branch' => $this->salesByBranch($tenant->id, $dateFrom, $dateTo, $filters),
                'by_cash_register' => $this->salesByCashRegister($tenant->id, $dateFrom, $dateTo, $filters),
                'by_cashier' => $this->salesByCashier($tenant->id, $dateFrom, $dateTo, $filters),
                'by_payment_method' => $this->salesByPaymentMethod($tenant->id, $dateFrom, $dateTo, $filters),
                'top_products' => $this->topProducts($tenant->id, $dateFrom, $dateTo, $filters),
            ],
            'data' => $orders,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'from' => $total === 0 ? 0 : (($page - 1) * $limit) + 1,
                'to' => min($page * $limit, $total),
                'has_previous' => $page > 1,
                'has_next' => $page * $limit < $total,
            ],
            'generated_at' => now()->toISOString(),
        ];
    }

    public function detail(PosOrder $order): array
    {
        $tenant = app(TenantManager::class)->require();

        if ((int) $order->tenant_id !== (int) $tenant->id) {
            throw new NotFoundHttpException();
        }

        $row = $this->baseOrdersQuery($tenant->id, now()->subYears(20), now()->addYears(20), ['status' => 'all'])
            ->where('pos_orders.id', $order->id)
            ->first($this->orderColumns());

        if (! $row) {
            throw new NotFoundHttpException();
        }

        return [
            ...$this->mapOrder($row),
            'items' => $this->items($tenant->id, $order->sale_id),
            'payments' => $this->payments($tenant->id, $order->id),
        ];
    }

    public function export(array $filters): array
    {
        $tenant = app(TenantManager::class)->require();
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $stamp = now()->format('Ymd-His');

        $rows = $this->baseOrdersQuery($tenant->id, $dateFrom, $dateTo, $filters)
            ->orderByDesc('pos_orders.id')
            ->limit(2000)
            ->get($this->orderColumns())
            ->map(fn ($order): array => [
                $order->id,
                $order->customer_name ?: 'Consumidor final',
                $this->statusLabel($order->status),
                $order->branch_name ?? 'Sin sucursal',
                $order->cash_register_name ?? 'Sin caja',
                $order->cashier_name ?? 'Sin cajero',
                round((float) $order->total_base_amount, 4),
                round((float) $order->paid_base_amount, 4),
                round(max((float) $order->total_base_amount - (float) $order->paid_base_amount, 0), 4),
                $order->opened_at,
                $order->paid_at,
            ])
            ->all();

        return [
            'filename' => "ventas-pos-{$tenant->slug}-{$stamp}.csv",
            'headers' => ['Orden', 'Cliente', 'Estado', 'Sucursal', 'Caja', 'Cajero', 'Total USD', 'Pagado USD', 'Saldo USD', 'Apertura', 'Pago'],
            'rows' => $rows,
        ];
    }

    private function baseOrdersQuery(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters): Builder
    {
        $query = DB::table('pos_orders')
            ->leftJoin('cash_register_sessions', 'cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
            ->leftJoin('cash_registers', 'cash_registers.id', '=', 'cash_register_sessions.cash_register_id')
            ->leftJoin('branches', 'branches.id', '=', 'cash_register_sessions.branch_id')
            ->leftJoin('users as cashiers', 'cashiers.id', '=', 'pos_orders.cashier_id')
            ->leftJoin('customers', function ($join): void {
                $join->on('customers.id', '=', 'pos_orders.customer_id')
                    ->on('customers.tenant_id', '=', 'pos_orders.tenant_id');
            })
            ->where('pos_orders.tenant_id', $tenantId)
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('cash_register_sessions.id')
                    ->orWhere('cash_register_sessions.tenant_id', $tenantId);
            })
            ->where(function ($query) use ($dateFrom, $dateTo): void {
                $query->whereBetween('pos_orders.opened_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('pos_orders.paid_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('pos_orders.closed_at', [$dateFrom, $dateTo]);
            });

        $this->applyFilters($query, $filters);

        return $query;
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('pos_orders.status', $filters['status']);
        }

        if ($filters['branch_id'] ?? null) {
            $query->where('cash_register_sessions.branch_id', $filters['branch_id']);
        }

        if ($filters['cash_register_id'] ?? null) {
            $query->where('cash_register_sessions.cash_register_id', $filters['cash_register_id']);
        }

        if ($filters['cashier_id'] ?? null) {
            $query->where('pos_orders.cashier_id', $filters['cashier_id']);
        }

        if ($filters['search'] ?? '') {
            $search = mb_strtolower($filters['search']);
            $like = '%'.$search.'%';
            $query->where(function ($query) use ($like, $search): void {
                if (ctype_digit($search)) {
                    $query->orWhere('pos_orders.id', (int) $search);
                }

                $query->orWhereRaw('lower(coalesce(pos_orders.customer_name, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(customers.document_number, pos_orders.sync_customer_document_number, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(cashiers.name, pos_orders.sync_cashier_name, \'\')) like ?', [$like])
                    ->orWhereExists(function ($exists) use ($like): void {
                        $exists->selectRaw('1')
                            ->from('sale_items')
                            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
                            ->whereColumn('sale_items.sale_id', 'pos_orders.sale_id')
                            ->whereColumn('sale_items.tenant_id', 'pos_orders.tenant_id')
                            ->where(function ($query) use ($like): void {
                                $query->whereRaw('lower(coalesce(products.name, \'\')) like ?', [$like])
                                    ->orWhereRaw('lower(coalesce(products.sku, \'\')) like ?', [$like]);
                            });
                    });
            });
        }
    }

    private function orderColumns(): array
    {
        return [
            'pos_orders.id',
            'pos_orders.sale_id',
            'pos_orders.status',
            'pos_orders.customer_name',
            'pos_orders.total_base_amount',
            'pos_orders.total_local_amount',
            'pos_orders.paid_base_amount',
            'pos_orders.paid_local_amount',
            'pos_orders.opened_at',
            'pos_orders.paid_at',
            'pos_orders.closed_at',
            DB::raw('coalesce(cash_registers.name, pos_orders.sync_cash_register_name) as cash_register_name'),
            DB::raw('coalesce(branches.name, pos_orders.sync_branch_name) as branch_name'),
            DB::raw('coalesce(cashiers.name, pos_orders.sync_cashier_name) as cashier_name'),
            DB::raw('coalesce(customers.document_type, pos_orders.sync_customer_document_type) as customer_document_type'),
            DB::raw('coalesce(customers.document_number, pos_orders.sync_customer_document_number) as customer_document_number'),
        ];
    }

    private function mapOrder(object $order): array
    {
        $total = (float) $order->total_base_amount;
        $paid = (float) $order->paid_base_amount;

        return [
            'id' => $order->id,
            'sale_id' => $order->sale_id,
            'status' => $order->status,
            'status_label' => $this->statusLabel($order->status),
            'customer_name' => $order->customer_name ?: 'Consumidor final',
            'customer_document' => trim(($order->customer_document_type ? $order->customer_document_type.'-' : '').($order->customer_document_number ?? '')),
            'cash_register_name' => $order->cash_register_name ?? 'Sin caja',
            'branch_name' => $order->branch_name ?? 'Sin sucursal',
            'cashier_name' => $order->cashier_name ?? 'Sin cajero',
            'total_base_amount' => round($total, 4),
            'total_local_amount' => round((float) $order->total_local_amount, 4),
            'paid_base_amount' => round($paid, 4),
            'paid_local_amount' => round((float) $order->paid_local_amount, 4),
            'balance_base_amount' => round(max($total - $paid, 0), 4),
            'opened_at' => $order->opened_at,
            'paid_at' => $order->paid_at,
            'closed_at' => $order->closed_at,
        ];
    }

    private function items(int $tenantId, int $saleId): array
    {
        return DB::table('sale_items')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'sale_items.warehouse_id')
            ->where('sale_items.tenant_id', $tenantId)
            ->where('sale_items.sale_id', $saleId)
            ->orderBy('sale_items.id')
            ->get([
                'sale_items.id',
                'products.name as product_name',
                'products.sku as product_sku',
                'warehouses.name as warehouse_name',
                'sale_items.quantity',
                'sale_items.sale_currency',
                'sale_items.unit_price',
                'sale_items.total_amount',
                'sale_items.base_unit_price',
                'sale_items.base_total_amount',
                'sale_items.discount_base_amount',
                'sale_items.discount_reason',
                'sale_items.product_unit_ids',
                'sale_items.warranty_policy_name',
                'sale_items.warranty_expires_at',
            ])
            ->map(fn ($item): array => [
                'id' => $item->id,
                'product_name' => $item->product_name ?? 'Producto eliminado',
                'product_sku' => $item->product_sku ?? '',
                'warehouse_name' => $item->warehouse_name ?? 'Sin almacen',
                'quantity' => round((float) $item->quantity, 4),
                'sale_currency' => $item->sale_currency,
                'unit_price' => round((float) $item->unit_price, 4),
                'total_amount' => round((float) $item->total_amount, 4),
                'base_unit_price' => round((float) $item->base_unit_price, 4),
                'base_total_amount' => round((float) $item->base_total_amount, 4),
                'discount_base_amount' => round((float) $item->discount_base_amount, 4),
                'discount_reason' => $item->discount_reason,
                'product_unit_ids' => $item->product_unit_ids ? json_decode($item->product_unit_ids, true) : [],
                'warranty_policy_name' => $item->warranty_policy_name,
                'warranty_expires_at' => $item->warranty_expires_at,
            ])
            ->all();
    }

    private function payments(int $tenantId, int $orderId): array
    {
        return DB::table('pos_payments')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'pos_payments.payment_method_id')
            ->where('pos_payments.tenant_id', $tenantId)
            ->where('pos_payments.pos_order_id', $orderId)
            ->orderBy('pos_payments.id')
            ->get([
                'pos_payments.id',
                'payment_methods.name as payment_method_name',
                'pos_payments.method',
                'pos_payments.currency',
                'pos_payments.amount',
                'pos_payments.amount_base',
                'pos_payments.amount_local',
                'pos_payments.status',
                'pos_payments.reference',
                'pos_payments.exchange_rate_type_code',
                'pos_payments.exchange_rate',
            ])
            ->map(fn ($payment): array => [
                'id' => $payment->id,
                'payment_method_name' => $payment->payment_method_name ?? $this->paymentMethodLabel($payment->method, $payment->currency),
                'method' => $payment->method,
                'currency' => $payment->currency,
                'amount' => round((float) $payment->amount, 4),
                'amount_base' => round((float) $payment->amount_base, 4),
                'amount_local' => round((float) $payment->amount_local, 4),
                'status' => $payment->status,
                'reference' => $payment->reference,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate === null ? null : round((float) $payment->exchange_rate, 6),
            ])
            ->all();
    }

    private function filterOptions(int $tenantId): array
    {
        $cashierIds = DB::table('pos_orders')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('cashier_id')
            ->distinct()
            ->pluck('cashier_id');

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

    private function salesByBranch(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters): array
    {
        return $this->baseOrdersQuery($tenantId, $dateFrom, $dateTo, $filters)
            ->selectRaw("coalesce(branches.name, pos_orders.sync_branch_name, 'Sin sucursal') as branch_name")
            ->selectRaw('count(*) as orders_count')
            ->selectRaw('sum(pos_orders.total_base_amount) as total_base_amount')
            ->selectRaw('sum(pos_orders.paid_base_amount) as paid_base_amount')
            ->groupByRaw("coalesce(branches.name, pos_orders.sync_branch_name, 'Sin sucursal')")
            ->orderByDesc('paid_base_amount')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->branch_name,
                'orders_count' => (int) $row->orders_count,
                'total_base_amount' => round((float) $row->total_base_amount, 4),
                'paid_base_amount' => round((float) $row->paid_base_amount, 4),
            ])
            ->all();
    }

    private function salesByCashier(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters): array
    {
        return $this->baseOrdersQuery($tenantId, $dateFrom, $dateTo, $filters)
            ->selectRaw("coalesce(cashiers.name, pos_orders.sync_cashier_name, 'Sin cajero') as cashier_name")
            ->selectRaw('count(*) as orders_count')
            ->selectRaw('sum(pos_orders.total_base_amount) as total_base_amount')
            ->selectRaw('sum(pos_orders.paid_base_amount) as paid_base_amount')
            ->groupByRaw("coalesce(cashiers.name, pos_orders.sync_cashier_name, 'Sin cajero')")
            ->orderByDesc('paid_base_amount')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->cashier_name,
                'orders_count' => (int) $row->orders_count,
                'total_base_amount' => round((float) $row->total_base_amount, 4),
                'paid_base_amount' => round((float) $row->paid_base_amount, 4),
            ])
            ->all();
    }

    private function salesByCashRegister(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters): array
    {
        return $this->baseOrdersQuery($tenantId, $dateFrom, $dateTo, $filters)
            ->selectRaw("coalesce(cash_registers.name, pos_orders.sync_cash_register_name, 'Sin caja') as cash_register_name")
            ->selectRaw("coalesce(branches.name, pos_orders.sync_branch_name, 'Sin sucursal') as branch_name")
            ->selectRaw('count(*) as orders_count')
            ->selectRaw('sum(pos_orders.total_base_amount) as total_base_amount')
            ->selectRaw('sum(pos_orders.paid_base_amount) as paid_base_amount')
            ->groupByRaw("coalesce(cash_registers.name, pos_orders.sync_cash_register_name, 'Sin caja'), coalesce(branches.name, pos_orders.sync_branch_name, 'Sin sucursal')")
            ->orderByDesc('paid_base_amount')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->cash_register_name,
                'branch_name' => $row->branch_name,
                'orders_count' => (int) $row->orders_count,
                'total_base_amount' => round((float) $row->total_base_amount, 4),
                'paid_base_amount' => round((float) $row->paid_base_amount, 4),
            ])
            ->all();
    }

    private function salesByPaymentMethod(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters): array
    {
        $query = DB::table('pos_payments')
            ->join('pos_orders', function ($join): void {
                $join->on('pos_orders.id', '=', 'pos_payments.pos_order_id')
                    ->on('pos_orders.tenant_id', '=', 'pos_payments.tenant_id');
            })
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'pos_payments.payment_method_id')
            ->leftJoin('cash_register_sessions', 'cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
            ->leftJoin('cash_registers', 'cash_registers.id', '=', 'cash_register_sessions.cash_register_id')
            ->leftJoin('branches', 'branches.id', '=', 'cash_register_sessions.branch_id')
            ->leftJoin('users as cashiers', 'cashiers.id', '=', 'pos_orders.cashier_id')
            ->leftJoin('customers', function ($join): void {
                $join->on('customers.id', '=', 'pos_orders.customer_id')
                    ->on('customers.tenant_id', '=', 'pos_orders.tenant_id');
            })
            ->where('pos_payments.tenant_id', $tenantId)
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('cash_register_sessions.id')
                    ->orWhere('cash_register_sessions.tenant_id', $tenantId);
            })
            ->where(function ($query) use ($dateFrom, $dateTo): void {
                $query->whereBetween('pos_orders.opened_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('pos_orders.paid_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('pos_orders.closed_at', [$dateFrom, $dateTo]);
            });

        $this->applyFilters($query, $filters);

        return $query
            ->selectRaw("coalesce(payment_methods.name, 'Metodo sin configurar') as payment_method_name")
            ->selectRaw('pos_payments.currency')
            ->selectRaw('count(*) as payments_count')
            ->selectRaw('sum(pos_payments.amount_base) as amount_base')
            ->groupByRaw("coalesce(payment_methods.name, 'Metodo sin configurar'), pos_payments.currency")
            ->orderByDesc('amount_base')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->payment_method_name,
                'currency' => $row->currency,
                'payments_count' => (int) $row->payments_count,
                'amount_base' => round((float) $row->amount_base, 4),
            ])
            ->all();
    }

    private function topProducts(int $tenantId, Carbon $dateFrom, Carbon $dateTo, array $filters): array
    {
        $query = DB::table('sale_items')
            ->join('pos_orders', function ($join): void {
                $join->on('pos_orders.sale_id', '=', 'sale_items.sale_id')
                    ->on('pos_orders.tenant_id', '=', 'sale_items.tenant_id');
            })
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('cash_register_sessions', 'cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
            ->leftJoin('cash_registers', 'cash_registers.id', '=', 'cash_register_sessions.cash_register_id')
            ->leftJoin('branches', 'branches.id', '=', 'cash_register_sessions.branch_id')
            ->leftJoin('users as cashiers', 'cashiers.id', '=', 'pos_orders.cashier_id')
            ->leftJoin('customers', function ($join): void {
                $join->on('customers.id', '=', 'pos_orders.customer_id')
                    ->on('customers.tenant_id', '=', 'pos_orders.tenant_id');
            })
            ->where('sale_items.tenant_id', $tenantId)
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('cash_register_sessions.id')
                    ->orWhere('cash_register_sessions.tenant_id', $tenantId);
            })
            ->where(function ($query) use ($dateFrom, $dateTo): void {
                $query->whereBetween('pos_orders.opened_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('pos_orders.paid_at', [$dateFrom, $dateTo])
                    ->orWhereBetween('pos_orders.closed_at', [$dateFrom, $dateTo]);
            });

        $this->applyFilters($query, $filters);

        return $query
            ->selectRaw("coalesce(products.name, 'Producto eliminado') as product_name")
            ->selectRaw("coalesce(products.sku, '') as product_sku")
            ->selectRaw('sum(sale_items.quantity) as quantity')
            ->selectRaw('sum(sale_items.base_total_amount) as total_base_amount')
            ->groupByRaw("coalesce(products.name, 'Producto eliminado'), coalesce(products.sku, '')")
            ->orderByDesc('quantity')
            ->orderByDesc('total_base_amount')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'product_name' => $row->product_name,
                'product_sku' => $row->product_sku,
                'quantity' => round((float) $row->quantity, 4),
                'total_base_amount' => round((float) $row->total_base_amount, 4),
            ])
            ->all();
    }

    private function selectedFilters(array $filters): array
    {
        return [
            'branch_id' => $filters['branch_id'] ?? null,
            'cash_register_id' => $filters['cash_register_id'] ?? null,
            'cashier_id' => $filters['cashier_id'] ?? null,
            'status' => $filters['status'] ?? 'all',
            'search' => $filters['search'] ?? '',
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

    private function sum(Builder $query, string $column): float
    {
        return round((float) (clone $query)->sum($column), 4);
    }

    private function statusLabel(string $status): string
    {
        return [
            PosOrder::STATUS_OPEN => 'Pendiente',
            PosOrder::STATUS_PAID => 'Pagada',
            PosOrder::STATUS_CANCELLED => 'Cancelada',
        ][$status] ?? ucfirst($status);
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
}
