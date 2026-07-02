<?php

namespace App\Modules\Kardex\Services;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;

class KardexService
{
    private const IN_TYPES = [
        'purchase',
        'sale_return',
        'adjustment_in',
        'transfer_in',
        'return_in',
        'released',
    ];

    private const OUT_TYPES = [
        'sale',
        'adjustment_out',
        'transfer_out',
        'return_out',
        'damaged',
        'reserved',
    ];

    public function product(Product $product, array $filters = []): array
    {
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $openingBalance = $dateFrom
            ? $this->signedMovements($product, $warehouseId)
                ->whereDate('created_at', '<', $dateFrom)
                ->get()
                ->sum(fn (StockMovement $movement): float => $this->signedQuantity($movement))
            : 0.0;

        $runningBalance = (float) $openingBalance;
        $movements = $this->signedMovements($product, $warehouseId)
            ->with(['warehouse', 'product'])
            ->when($dateFrom, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (StockMovement $movement) use (&$runningBalance): array {
                $quantityIn = $this->quantityIn($movement);
                $quantityOut = $this->quantityOut($movement);
                $runningBalance += $quantityIn - $quantityOut;

                return [
                    'id' => $movement->id,
                    'date' => $movement->created_at?->toISOString(),
                    'warehouse_id' => $movement->warehouse_id,
                    'warehouse_name' => $movement->warehouse?->name,
                    'product_id' => $movement->product_id,
                    'product_name' => $movement->product?->name,
                    'type' => $movement->type,
                    'quantity_in' => round($quantityIn, 4),
                    'quantity_out' => round($quantityOut, 4),
                    'running_balance' => round($runningBalance, 4),
                    'unit_cost' => $movement->unit_cost === null ? null : (float) $movement->unit_cost,
                    'reason' => $movement->reason,
                    'reference_type' => $movement->reference_type,
                    'reference_id' => $movement->reference_id,
                ];
            });

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'warehouse_id' => $warehouseId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'opening_balance' => round((float) $openingBalance, 4),
            'closing_balance' => round($runningBalance, 4),
            'movements' => $movements->values()->all(),
        ];
    }

    private function signedMovements(Product $product, ?int $warehouseId)
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId));
    }

    private function quantityIn(StockMovement $movement): float
    {
        return in_array($movement->type, self::IN_TYPES, true) ? (float) $movement->quantity : 0.0;
    }

    private function quantityOut(StockMovement $movement): float
    {
        return in_array($movement->type, self::OUT_TYPES, true) ? (float) $movement->quantity : 0.0;
    }

    private function signedQuantity(StockMovement $movement): float
    {
        return $this->quantityIn($movement) - $this->quantityOut($movement);
    }
}
