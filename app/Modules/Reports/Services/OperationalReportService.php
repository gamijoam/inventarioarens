<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegisterMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OperationalReportService
{
    public function catalog(User $user): array
    {
        return [
            ['key' => 'daily', 'label' => 'Día operativo', 'permission' => 'reports.view', 'available' => $this->canAny($user, ['reports.view', 'reports.sales.view', 'reports.cash.view'])],
            ['key' => 'sales', 'label' => 'Ventas detalladas', 'permission' => 'reports.sales.view', 'available' => $this->canAny($user, ['reports.view', 'reports.sales.view'])],
            ['key' => 'cash', 'label' => 'Cajas y POS', 'permission' => 'reports.cash.view', 'available' => $this->canAny($user, ['reports.view', 'reports.cash.view'])],
            ['key' => 'payments', 'label' => 'Métodos de pago', 'permission' => 'reports.cash.view', 'available' => $this->canAny($user, ['reports.view', 'reports.cash.view', 'reports.sales.view'])],
            ['key' => 'stock', 'label' => 'Inventario', 'permission' => 'reports.inventory.view', 'available' => $this->canAny($user, ['reports.view', 'reports.inventory.view'])],
            ['key' => 'movements', 'label' => 'Movimientos', 'permission' => 'reports.movements.view', 'available' => $this->canAny($user, ['reports.view', 'reports.movements.view'])],
            ['key' => 'finance', 'label' => 'Finanzas', 'permission' => 'finance_reports.view', 'available' => $user->can('finance_reports.view')],
        ];
    }

    public function dailyOperations(array $filters): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        [$from, $to] = $this->dateRange($filters);

        $sales = $this->salesQuery($tenantId, $from, $to, $filters);
        $paidOrders = $this->posOrdersQuery($tenantId, $from, $to, $filters, PosOrder::STATUS_PAID);
        $openOrders = $this->posOrdersQuery($tenantId, $from, $to, $filters, PosOrder::STATUS_OPEN);
        $receivables = $this->receivablesQuery($tenantId, $from, $to, $filters);
        $requestedReturns = $this->returnsQuery($tenantId, $from, $to, $filters, SalesReturn::STATUS_REQUESTED, 'created_at');
        $processedReturns = $this->returnsQuery($tenantId, $from, $to, $filters, SalesReturn::STATUS_PROCESSED, 'processed_at');
        $sessions = $this->sessionsQuery($tenantId, $from, $to, $filters);

        return [
            'period' => $this->period($from, $to),
            'currency' => 'USD',
            'sales' => [
                'confirmed_count' => (clone $sales)->where('sales.status', Sale::STATUS_CONFIRMED)->count(),
                'confirmed_base_amount' => $this->sum($sales, 'sales.total_base_amount'),
                'pos_paid_count' => (clone $paidOrders)->count(),
                'pos_paid_base_amount' => $this->sum($paidOrders, 'pos_orders.paid_base_amount'),
                'pos_open_count' => (clone $openOrders)->count(),
                'pos_open_base_amount' => $this->sum($openOrders, 'pos_orders.total_base_amount'),
                'credit_count' => (clone $receivables)->where('balance_base_amount', '>', 0)->count(),
                'credit_balance_base_amount' => $this->sum($receivables, 'balance_base_amount'),
            ],
            'returns' => [
                'requested_count' => (clone $requestedReturns)->count(),
                'processed_count' => (clone $processedReturns)->count(),
            ],
            'cash' => [
                'open_count' => (clone $sessions)->where('cash_register_sessions.status', CashRegisterSession::STATUS_OPEN)->count(),
                'closed_count' => (clone $sessions)->where('cash_register_sessions.status', CashRegisterSession::STATUS_CLOSED)->count(),
                'expected_base_amount' => $this->sum($sessions, 'cash_register_sessions.expected_base_amount'),
                'expected_local_amount' => $this->sum($sessions, 'cash_register_sessions.expected_local_amount'),
                'difference_base_amount' => $this->sum(
                    (clone $sessions)->where('cash_register_sessions.status', CashRegisterSession::STATUS_CLOSED),
                    'cash_register_sessions.difference_base_amount'
                ),
            ],
            'payment_methods' => $this->paymentMethods($filters, 8),
            'alerts' => $this->alerts($tenantId, $from, $to, $filters),
            'generated_at' => now()->toISOString(),
        ];
    }

    public function salesDetail(array $filters): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        [$from, $to] = $this->dateRange($filters);
        $limit = $filters['limit'] ?? 25;

        $query = Sale::query()
            ->with([
                'customer',
                'creator',
                'items.product',
                'items.warehouse',
                'posOrder.cashier',
                'posOrder.cashRegisterSession.branch',
                'posOrder.cashRegisterSession.cashRegister',
                'posOrder.payments.paymentMethod',
                'receivable',
                'salesReturns.items',
            ])
            ->withCount('items')
            ->when(($filters['status'] ?? 'all') !== 'all' && in_array($filters['status'], [Sale::STATUS_DRAFT, Sale::STATUS_CONFIRMED, Sale::STATUS_CANCELLED], true), fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['customer_id'] ?? null, fn ($query, int $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['cashier_id'] ?? null, fn ($query, int $cashierId) => $query->whereHas('posOrder', fn ($order) => $order->where('cashier_id', $cashierId)))
            ->when($filters['branch_id'] ?? null, fn ($query, int $branchId) => $query->whereHas('posOrder.cashRegisterSession', fn ($session) => $session->where('branch_id', $branchId)))
            ->when($filters['cash_register_id'] ?? null, fn ($query, int $registerId) => $query->whereHas('posOrder.cashRegisterSession', fn ($session) => $session->where('cash_register_id', $registerId)))
            ->when($filters['warehouse_id'] ?? null, fn ($query, int $warehouseId) => $query->whereHas('items', fn ($items) => $items->where('warehouse_id', $warehouseId)))
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('confirmed_at', [$from, $to])
                    ->orWhereBetween('created_at', [$from, $to]);
            })
            ->latest('id')
            ->limit($limit);

        return [
            'period' => $this->period($from, $to),
            'rows' => $query->get()->map(fn (Sale $sale): array => $this->saleRow($sale))->all(),
        ];
    }

    public function cashSessions(array $filters): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        [$from, $to] = $this->dateRange($filters);
        $limit = $filters['limit'] ?? 25;

        $sessions = CashRegisterSession::query()
            ->with(['branch', 'cashRegister', 'cashier', 'movements'])
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('opened_at', [$from, $to])
                    ->orWhereBetween('closed_at', [$from, $to])
                    ->orWhere('status', CashRegisterSession::STATUS_OPEN);
            })
            ->when(($filters['status'] ?? 'all') !== 'all' && in_array($filters['status'], [CashRegisterSession::STATUS_OPEN, CashRegisterSession::STATUS_CLOSED, CashRegisterSession::STATUS_CANCELLED], true), fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['branch_id'] ?? null, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['cash_register_id'] ?? null, fn ($query, int $registerId) => $query->where('cash_register_id', $registerId))
            ->when($filters['cashier_id'] ?? null, fn ($query, int $cashierId) => $query->where('cashier_id', $cashierId))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->latest('opened_at')
            ->limit($limit)
            ->get();

        return [
            'period' => $this->period($from, $to),
            'summary' => [
                'open_count' => $sessions->where('status', CashRegisterSession::STATUS_OPEN)->count(),
                'closed_count' => $sessions->where('status', CashRegisterSession::STATUS_CLOSED)->count(),
                'expected_base_amount' => round((float) $sessions->sum('expected_base_amount'), 4),
                'expected_local_amount' => round((float) $sessions->sum('expected_local_amount'), 4),
                'difference_base_amount' => round((float) $sessions->where('status', CashRegisterSession::STATUS_CLOSED)->sum('difference_base_amount'), 4),
            ],
            'rows' => $sessions->map(fn (CashRegisterSession $session): array => $this->cashSessionRow($session))->all(),
            'movement_breakdown' => $this->cashMovementBreakdown($tenantId, $from, $to, $filters),
        ];
    }

    public function paymentMethods(array $filters, int $limit = 25): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        [$from, $to] = $this->dateRange($filters);

        $query = DB::table('pos_payments')
            ->join('pos_orders', function ($join): void {
                $join->on('pos_orders.id', '=', 'pos_payments.pos_order_id')
                    ->on('pos_orders.tenant_id', '=', 'pos_payments.tenant_id');
            })
            ->leftJoin('cash_register_sessions', function ($join): void {
                $join->on('cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
                    ->on('cash_register_sessions.tenant_id', '=', 'pos_orders.tenant_id');
            })
            ->leftJoin('payment_methods', function ($join): void {
                $join->on('payment_methods.id', '=', 'pos_payments.payment_method_id')
                    ->on('payment_methods.tenant_id', '=', 'pos_payments.tenant_id');
            })
            ->where('pos_payments.tenant_id', $tenantId)
            ->where('pos_payments.status', PosPayment::STATUS_CAPTURED)
            ->whereBetween('pos_orders.paid_at', [$from, $to]);

        $this->applySessionFilters($query, $filters, 'cash_register_sessions');

        return $query
            ->groupBy('pos_payments.method', 'pos_payments.currency', 'payment_methods.name', 'payment_methods.requires_reference')
            ->orderByDesc(DB::raw('SUM(pos_payments.amount_base)'))
            ->limit($limit)
            ->get([
                'pos_payments.method',
                'pos_payments.currency',
                'payment_methods.name as payment_method_name',
                'payment_methods.requires_reference',
                DB::raw('COUNT(*) as payments_count'),
                DB::raw('SUM(pos_payments.amount_base) as amount_base'),
                DB::raw('SUM(pos_payments.amount_local) as amount_local'),
                DB::raw("SUM(CASE WHEN payment_methods.requires_reference = true AND (pos_payments.reference IS NULL OR pos_payments.reference = '') THEN 1 ELSE 0 END) as missing_reference_count"),
            ])
            ->map(fn ($method): array => [
                'method' => $method->method,
                'currency' => $method->currency,
                'name' => $method->payment_method_name ?? $this->paymentMethodLabel($method->method, $method->currency),
                'requires_reference' => (bool) $method->requires_reference,
                'payments_count' => (int) $method->payments_count,
                'amount_base' => round((float) $method->amount_base, 4),
                'amount_local' => round((float) $method->amount_local, 4),
                'missing_reference_count' => (int) $method->missing_reference_count,
            ])
            ->all();
    }

    private function saleRow(Sale $sale): array
    {
        $receivable = $sale->receivable;
        $posOrder = $sale->posOrder;

        return [
            'id' => $sale->id,
            'status' => $sale->status,
            'origin' => $posOrder ? 'POS' : 'Manual',
            'customer_name' => $sale->customer?->name ?? 'Consumidor Final',
            'created_by_name' => $sale->creator?->name,
            'cashier_name' => $posOrder?->cashier?->name,
            'confirmed_at' => $sale->confirmed_at?->toISOString(),
            'created_at' => $sale->created_at?->toISOString(),
            'total_base_amount' => (float) $sale->total_base_amount,
            'total_local_amount' => (float) $sale->total_local_amount,
            'items_count' => $sale->items_count,
            'collection' => [
                'status' => $receivable?->status ?? 'none',
                'balance_base_amount' => (float) ($receivable?->balance_base_amount ?? 0),
                'collected_base_amount' => (float) ($receivable?->collected_base_amount ?? 0),
            ],
            'pos_order' => $posOrder ? [
                'id' => $posOrder->id,
                'status' => $posOrder->status,
                'paid_base_amount' => (float) $posOrder->paid_base_amount,
                'paid_at' => $posOrder->paid_at?->toISOString(),
                'cash_register_session_id' => $posOrder->cash_register_session_id,
                'cash_register_name' => $posOrder->cashRegisterSession?->cashRegister?->name,
                'branch_name' => $posOrder->cashRegisterSession?->branch?->name,
            ] : null,
            'items' => $sale->items->map(fn (SaleItem $item): array => $this->saleItemRow($item))->all(),
            'payments' => $posOrder?->payments->map(fn (PosPayment $payment): array => [
                'id' => $payment->id,
                'method' => $payment->method,
                'payment_method_name' => $payment->paymentMethod?->name,
                'currency' => $payment->currency,
                'amount' => (float) $payment->amount,
                'amount_base' => (float) $payment->amount_base,
                'amount_local' => (float) $payment->amount_local,
                'exchange_rate_type_code' => $payment->exchange_rate_type_code,
                'exchange_rate' => $payment->exchange_rate === null ? null : (float) $payment->exchange_rate,
                'reference' => $payment->reference,
            ])->all() ?? [],
            'returns' => $sale->salesReturns->map(fn (SalesReturn $return): array => [
                'id' => $return->id,
                'status' => $return->status,
                'reason' => $return->reason,
                'items_count' => $return->items->count(),
                'processed_at' => $return->processed_at?->toISOString(),
            ])->all(),
        ];
    }

    private function saleItemRow(SaleItem $item): array
    {
        $unitIds = $item->product_unit_ids ?? [];

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product?->name,
            'sku' => $item->product?->sku,
            'warehouse_name' => $item->warehouse?->name,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'base_total_amount' => (float) $item->base_total_amount,
            'discount_base_amount' => (float) $item->discount_base_amount,
            'discount_reason' => $item->discount_reason,
            'exchange_rate_type_code' => $item->exchange_rate_type_code,
            'exchange_rate' => $item->exchange_rate === null ? null : (float) $item->exchange_rate,
            'serial_units' => $unitIds === [] ? [] : ProductUnit::query()
                ->whereIn('id', $unitIds)
                ->get()
                ->sortBy(fn (ProductUnit $unit): int => array_search($unit->id, $unitIds, true))
                ->map(fn (ProductUnit $unit): array => [
                    'id' => $unit->id,
                    'serial_type' => $unit->serial_type,
                    'serial_number' => $unit->serial_number,
                    'status' => $unit->status,
                ])
                ->values()
                ->all(),
            'warranty_policy_name' => $item->warranty_policy_name,
            'warranty_expires_at' => $item->warranty_expires_at?->toISOString(),
        ];
    }

    private function cashSessionRow(CashRegisterSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status,
            'branch_name' => $session->branch?->name,
            'cash_register_name' => $session->cashRegister?->name,
            'cashier_name' => $session->cashier?->name,
            'opening_base_amount' => (float) $session->opening_base_amount,
            'opening_local_amount' => (float) $session->opening_local_amount,
            'expected_base_amount' => (float) $session->expected_base_amount,
            'expected_local_amount' => (float) $session->expected_local_amount,
            'counted_base_amount' => $session->counted_base_amount === null ? null : (float) $session->counted_base_amount,
            'counted_local_amount' => $session->counted_local_amount === null ? null : (float) $session->counted_local_amount,
            'difference_base_amount' => $session->status === CashRegisterSession::STATUS_CLOSED ? (float) $session->difference_base_amount : null,
            'difference_local_amount' => $session->status === CashRegisterSession::STATUS_CLOSED ? (float) $session->difference_local_amount : null,
            'opened_at' => $session->opened_at?->toISOString(),
            'closed_at' => $session->closed_at?->toISOString(),
            'movements' => $session->movements->map(fn (CashRegisterMovement $movement): array => [
                'id' => $movement->id,
                'type' => $movement->type,
                'method' => $movement->method,
                'currency' => $movement->currency,
                'amount_base' => (float) $movement->amount_base,
                'amount_local' => (float) $movement->amount_local,
                'reference' => $movement->reference,
                'created_at' => $movement->created_at?->toISOString(),
            ])->all(),
        ];
    }

    private function cashMovementBreakdown(int $tenantId, Carbon $from, Carbon $to, array $filters): array
    {
        $query = DB::table('cash_register_movements')
            ->join('cash_register_sessions', function ($join): void {
                $join->on('cash_register_sessions.id', '=', 'cash_register_movements.cash_register_session_id')
                    ->on('cash_register_sessions.tenant_id', '=', 'cash_register_movements.tenant_id');
            })
            ->where('cash_register_movements.tenant_id', $tenantId)
            ->whereBetween('cash_register_movements.created_at', [$from, $to]);

        $this->applySessionFilters($query, $filters, 'cash_register_sessions');

        return $query
            ->groupBy('cash_register_movements.type', 'cash_register_movements.method', 'cash_register_movements.currency')
            ->orderBy('cash_register_movements.type')
            ->get([
                'cash_register_movements.type',
                'cash_register_movements.method',
                'cash_register_movements.currency',
                DB::raw('COUNT(*) as movements_count'),
                DB::raw('SUM(cash_register_movements.amount_base) as amount_base'),
                DB::raw('SUM(cash_register_movements.amount_local) as amount_local'),
            ])
            ->map(fn ($row): array => [
                'type' => $row->type,
                'method' => $row->method,
                'currency' => $row->currency,
                'movements_count' => (int) $row->movements_count,
                'amount_base' => round((float) $row->amount_base, 4),
                'amount_local' => round((float) $row->amount_local, 4),
            ])
            ->all();
    }

    private function alerts(int $tenantId, Carbon $from, Carbon $to, array $filters): array
    {
        $sessions = $this->sessionsQuery($tenantId, $from, $to, $filters);
        $staleOpenSessions = (clone $sessions)
            ->where('cash_register_sessions.status', CashRegisterSession::STATUS_OPEN)
            ->where('cash_register_sessions.opened_at', '<', $from)
            ->count();

        $closedWithDifference = (clone $sessions)
            ->where('cash_register_sessions.status', CashRegisterSession::STATUS_CLOSED)
            ->whereRaw('ABS(COALESCE(cash_register_sessions.difference_base_amount, 0)) > 0.0001')
            ->count();

        $missingReference = collect($this->paymentMethods($filters, 100))->sum('missing_reference_count');

        $paidWithoutSession = DB::table('pos_orders')
            ->where('tenant_id', $tenantId)
            ->where('status', PosOrder::STATUS_PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->whereNull('cash_register_session_id')
            ->count();

        return [
            'stale_open_sessions' => $staleOpenSessions,
            'closed_sessions_with_difference' => $closedWithDifference,
            'payments_missing_reference' => $missingReference,
            'paid_pos_without_cash_session' => $paidWithoutSession,
        ];
    }

    private function salesQuery(int $tenantId, Carbon $from, Carbon $to, array $filters): Builder
    {
        $query = DB::table('sales')
            ->where('sales.tenant_id', $tenantId)
            ->whereBetween('sales.confirmed_at', [$from, $to]);

        if ($filters['warehouse_id'] ?? null) {
            $query->whereExists(function ($sub) use ($filters): void {
                $sub->selectRaw('1')
                    ->from('sale_items')
                    ->whereColumn('sale_items.sale_id', 'sales.id')
                    ->whereColumn('sale_items.tenant_id', 'sales.tenant_id')
                    ->where('sale_items.warehouse_id', $filters['warehouse_id']);
            });
        }

        return $query;
    }

    private function posOrdersQuery(int $tenantId, Carbon $from, Carbon $to, array $filters, string $status): Builder
    {
        $query = DB::table('pos_orders')
            ->leftJoin('cash_register_sessions', function ($join): void {
                $join->on('cash_register_sessions.id', '=', 'pos_orders.cash_register_session_id')
                    ->on('cash_register_sessions.tenant_id', '=', 'pos_orders.tenant_id');
            })
            ->where('pos_orders.tenant_id', $tenantId)
            ->where('pos_orders.status', $status);

        if ($status === PosOrder::STATUS_PAID) {
            $query->whereBetween('pos_orders.paid_at', [$from, $to]);
        } else {
            $query->whereBetween('pos_orders.opened_at', [$from, $to]);
        }

        $this->applyOrderFilters($query, $filters);

        return $query;
    }

    private function receivablesQuery(int $tenantId, Carbon $from, Carbon $to, array $filters): Builder
    {
        return DB::table('accounts_receivables')
            ->where('tenant_id', $tenantId)
            ->whereBetween('opened_at', [$from, $to])
            ->when($filters['customer_id'] ?? null, fn (Builder $query, int $customerId) => $query->where('customer_id', $customerId));
    }

    private function returnsQuery(int $tenantId, Carbon $from, Carbon $to, array $filters, string $status, string $dateColumn): Builder
    {
        $query = DB::table('sales_returns')
            ->join('sales', function ($join): void {
                $join->on('sales.id', '=', 'sales_returns.sale_id')
                    ->on('sales.tenant_id', '=', 'sales_returns.tenant_id');
            })
            ->where('sales_returns.tenant_id', $tenantId)
            ->where('sales_returns.status', $status)
            ->whereBetween("sales_returns.{$dateColumn}", [$from, $to]);

        if ($filters['customer_id'] ?? null) {
            $query->where('sales.customer_id', $filters['customer_id']);
        }

        return $query;
    }

    private function sessionsQuery(int $tenantId, Carbon $from, Carbon $to, array $filters): Builder
    {
        $query = DB::table('cash_register_sessions')
            ->where('cash_register_sessions.tenant_id', $tenantId)
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('cash_register_sessions.opened_at', [$from, $to])
                    ->orWhereBetween('cash_register_sessions.closed_at', [$from, $to])
                    ->orWhere('cash_register_sessions.status', CashRegisterSession::STATUS_OPEN);
            });

        $this->applySessionFilters($query, $filters, 'cash_register_sessions');

        return $query;
    }

    private function applyOrderFilters(Builder $query, array $filters): void
    {
        $this->applySessionFilters($query, $filters, 'cash_register_sessions');

        if ($filters['cashier_id'] ?? null) {
            $query->where('pos_orders.cashier_id', $filters['cashier_id']);
        }
    }

    private function applySessionFilters(Builder $query, array $filters, string $table): void
    {
        if ($filters['branch_id'] ?? null) {
            $query->where("{$table}.branch_id", $filters['branch_id']);
        }

        if ($filters['cash_register_id'] ?? null) {
            $query->where("{$table}.cash_register_id", $filters['cash_register_id']);
        }

        if ($filters['cashier_id'] ?? null && $table === 'cash_register_sessions') {
            $query->where("{$table}.cashier_id", $filters['cashier_id']);
        }
    }

    private function dateRange(array $filters): array
    {
        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null)) {
            return [
                Carbon::parse($filters['date_from'])->startOfDay(),
                Carbon::parse($filters['date_to'])->endOfDay(),
            ];
        }

        $date = Carbon::parse($filters['date'] ?? now()->toDateString());

        return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
    }

    private function period(Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_datetime' => $from->toISOString(),
            'to_datetime' => $to->toISOString(),
        ];
    }

    private function canAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    private function paymentMethodLabel(?string $method, ?string $currency): string
    {
        $label = [
            PaymentMethod::CURRENCY_FLEXIBLE => 'Flexible',
            PosPayment::METHOD_CASH => 'Efectivo',
            PosPayment::METHOD_CARD => 'Tarjeta',
            PosPayment::METHOD_MOBILE_PAYMENT => 'Pago móvil',
            PosPayment::METHOD_TRANSFER => 'Transferencia',
            PosPayment::METHOD_ZELLE => 'Zelle',
            PosPayment::METHOD_EXTERNAL_FINANCING => 'Financiadora',
            PosPayment::METHOD_OTHER => 'Otro',
        ][$method] ?? 'Método';

        return trim($label.' '.($currency ?: ''));
    }

    private function sum(Builder $query, string $column): float
    {
        return round((float) (clone $query)->sum($column), 4);
    }
}
