<?php

namespace App\Modules\AdminPortal\Services;

use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    public function summary(array $filters): array
    {
        $tenant = app(TenantManager::class)->require();
        $tenantId = $tenant->id;
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $threshold = (float) ($filters['low_stock_threshold'] ?? 3);

        $sales = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->whereBetween('confirmed_at', [$dateFrom, $dateTo]);

        $posPaid = DB::table('pos_orders')
            ->where('tenant_id', $tenantId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$dateFrom, $dateTo]);

        $inventory = $this->inventorySummary($tenantId, $threshold);
        $sync = $this->syncSummary($tenantId);

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
                'confirmed_count' => (clone $sales)->count(),
                'confirmed_base_amount' => $this->sum($sales, 'total_base_amount'),
                'pos_paid_count' => (clone $posPaid)->count(),
                'pos_paid_base_amount' => $this->sum($posPaid, 'paid_base_amount'),
                'pending_pos_count' => DB::table('pos_orders')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'open')
                    ->count(),
            ],
            'cash_register' => [
                'physical_registers_count' => DB::table('cash_registers')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->count(),
                'open_sessions_count' => DB::table('cash_register_sessions')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'open')
                    ->count(),
                'expected_base_amount' => $this->sum(
                    DB::table('cash_register_sessions')
                        ->where('tenant_id', $tenantId)
                        ->where('status', 'open'),
                    'expected_base_amount'
                ),
            ],
            'inventory' => $inventory,
            'sync' => $sync,
            'alerts' => $this->alerts($inventory, $sync),
            'generated_at' => now()->toISOString(),
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

    private function inventorySummary(int $tenantId, float $threshold): array
    {
        $stockByProduct = DB::table('stock_balances')
            ->select('product_id')
            ->selectRaw('COALESCE(SUM(quantity_available), 0) as available')
            ->selectRaw('COALESCE(SUM(quantity_reserved), 0) as reserved')
            ->selectRaw('COALESCE(SUM(quantity_damaged), 0) as damaged')
            ->where('tenant_id', $tenantId)
            ->groupBy('product_id');

        $productsWithStock = DB::table('products')
            ->leftJoinSub($stockByProduct, 'stock', 'stock.product_id', '=', 'products.id')
            ->where('products.tenant_id', $tenantId)
            ->where('products.is_active', true);

        return [
            'active_products_count' => (clone $productsWithStock)->count(),
            'available_quantity' => round((float) (clone $productsWithStock)->sum(DB::raw('COALESCE(stock.available, 0)')), 4),
            'reserved_quantity' => round((float) (clone $productsWithStock)->sum(DB::raw('COALESCE(stock.reserved, 0)')), 4),
            'damaged_quantity' => round((float) (clone $productsWithStock)->sum(DB::raw('COALESCE(stock.damaged, 0)')), 4),
            'low_stock_count' => (clone $productsWithStock)
                ->whereRaw('COALESCE(stock.available, 0) > 0')
                ->whereRaw('COALESCE(stock.available, 0) <= ?', [$threshold])
                ->count(),
            'without_stock_count' => (clone $productsWithStock)
                ->whereRaw('COALESCE(stock.available, 0) <= 0')
                ->count(),
            'low_stock_threshold' => $threshold,
        ];
    }

    private function syncSummary(int $tenantId): array
    {
        $readiness = DB::table('sync_tenant_readiness')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('last_success_at')
            ->orderByDesc('id')
            ->first();

        return [
            'nodes_count' => DB::table('sync_nodes')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->count(),
            'pending_outbox_count' => DB::table('sync_outbox')
                ->where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->count(),
            'failed_outbox_count' => DB::table('sync_outbox')
                ->where('tenant_id', $tenantId)
                ->where('status', 'failed')
                ->count(),
            'failed_inbox_count' => DB::table('sync_inbox')
                ->where('tenant_id', $tenantId)
                ->where('status', 'failed')
                ->count(),
            'readiness_status' => $readiness?->status ?? 'not_configured',
            'last_success_at' => $readiness?->last_success_at,
            'last_error' => $readiness?->last_error,
        ];
    }

    private function alerts(array $inventory, array $sync): array
    {
        $alerts = [];

        if ($inventory['without_stock_count'] > 0) {
            $alerts[] = [
                'type' => 'without_stock',
                'severity' => 'warning',
                'message' => 'Hay productos activos sin disponibilidad.',
                'count' => $inventory['without_stock_count'],
            ];
        }

        if ($inventory['low_stock_count'] > 0) {
            $alerts[] = [
                'type' => 'low_stock',
                'severity' => 'warning',
                'message' => 'Hay productos por debajo del minimo operativo.',
                'count' => $inventory['low_stock_count'],
            ];
        }

        if ($sync['failed_outbox_count'] > 0 || $sync['failed_inbox_count'] > 0) {
            $alerts[] = [
                'type' => 'sync_errors',
                'severity' => 'critical',
                'message' => 'Hay eventos de sincronizacion con error.',
                'count' => $sync['failed_outbox_count'] + $sync['failed_inbox_count'],
            ];
        }

        if ($sync['pending_outbox_count'] > 0) {
            $alerts[] = [
                'type' => 'sync_pending',
                'severity' => 'info',
                'message' => 'Hay cambios locales pendientes por subir.',
                'count' => $sync['pending_outbox_count'],
            ];
        }

        return $alerts;
    }

    private function sum($query, string $column): float
    {
        return round((float) (clone $query)->sum($column), 4);
    }
}
