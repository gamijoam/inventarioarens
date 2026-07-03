<?php

namespace App\Modules\InventoryCenter\Services;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;

class InventoryCenterProductDetailService
{
    public function detail(Product $product): array
    {
        $product->load(['saleExchangeRateType', 'warrantyPolicy']);

        return [
            'product' => $this->product($product),
            'stock' => [
                'totals' => $this->stockTotals($product),
                'by_warehouse' => $this->stockByWarehouse($product),
            ],
            'serials' => $this->serials($product),
            'recent_movements' => $this->recentMovements($product),
        ];
    }

    private function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'tracking_type' => $product->tracking_type,
            'base_price' => $product->base_price === null ? null : (float) $product->base_price,
            'sale_currency' => $product->sale_currency,
            'sale_exchange_rate_type_id' => $product->sale_exchange_rate_type_id,
            'sale_exchange_rate_type' => $product->saleExchangeRateType ? [
                'id' => $product->saleExchangeRateType->id,
                'code' => $product->saleExchangeRateType->code,
                'name' => $product->saleExchangeRateType->name,
                'is_default' => (bool) $product->saleExchangeRateType->is_default,
                'is_active' => (bool) $product->saleExchangeRateType->is_active,
            ] : null,
            'warranty_policy_id' => $product->warranty_policy_id,
            'warranty_policy' => $product->warrantyPolicy ? [
                'id' => $product->warrantyPolicy->id,
                'name' => $product->warrantyPolicy->name,
                'duration_days' => $product->warrantyPolicy->duration_days,
                'coverage_type' => $product->warrantyPolicy->coverage_type,
                'is_active' => (bool) $product->warrantyPolicy->is_active,
            ] : null,
            'is_active' => (bool) $product->is_active,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    private function stockTotals(Product $product): array
    {
        $totals = StockBalance::query()
            ->where('product_id', $product->id)
            ->selectRaw('COALESCE(SUM(quantity_available), 0) as available')
            ->selectRaw('COALESCE(SUM(quantity_reserved), 0) as reserved')
            ->selectRaw('COALESCE(SUM(quantity_damaged), 0) as damaged')
            ->first();

        return [
            'available' => $this->roundStock((float) $totals->available),
            'reserved' => $this->roundStock((float) $totals->reserved),
            'damaged' => $this->roundStock((float) $totals->damaged),
        ];
    }

    private function stockByWarehouse(Product $product): array
    {
        return StockBalance::query()
            ->where('product_id', $product->id)
            ->with(['warehouse.branch'])
            ->orderBy('warehouse_id')
            ->get()
            ->map(fn (StockBalance $balance): array => [
                'warehouse_id' => $balance->warehouse_id,
                'warehouse_name' => $balance->warehouse?->name,
                'warehouse_code' => $balance->warehouse?->code,
                'branch_id' => $balance->warehouse?->branch_id,
                'branch_name' => $balance->warehouse?->branch?->name,
                'available' => $this->roundStock((float) $balance->quantity_available),
                'reserved' => $this->roundStock((float) $balance->quantity_reserved),
                'damaged' => $this->roundStock((float) $balance->quantity_damaged),
            ])
            ->all();
    }

    private function serials(Product $product): array
    {
        if (! $product->requiresSerializedTracking()) {
            return [
                'total' => 0,
                'items' => [],
            ];
        }

        $query = ProductUnit::query()
            ->where('product_id', $product->id);

        return [
            'total' => (clone $query)->count(),
            'items' => $query
                ->with('warehouse')
                ->orderBy('status')
                ->orderBy('serial_number')
                ->limit(50)
                ->get()
                ->map(fn (ProductUnit $unit): array => [
                    'id' => $unit->id,
                    'serial_type' => $unit->serial_type,
                    'serial_number' => $unit->serial_number,
                    'status' => $unit->status,
                    'warehouse_id' => $unit->warehouse_id,
                    'warehouse_name' => $unit->warehouse?->name,
                ])
                ->all(),
        ];
    }

    private function recentMovements(Product $product): array
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->with(['warehouse', 'creator'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity' => $this->roundStock((float) $movement->quantity),
                'unit_cost' => $movement->unit_cost === null ? null : (float) $movement->unit_cost,
                'reason' => $movement->reason,
                'warehouse_id' => $movement->warehouse_id,
                'warehouse_name' => $movement->warehouse?->name,
                'created_by' => $movement->created_by,
                'created_by_name' => $movement->creator?->name,
                'created_at' => $movement->created_at?->toISOString(),
            ])
            ->all();
    }

    private function roundStock(float $value): float
    {
        return round($value, 4);
    }
}
