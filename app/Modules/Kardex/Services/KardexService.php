<?php

namespace App\Modules\Kardex\Services;

use App\Modules\AccessControl\Services\ScopeResolver;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use Illuminate\Http\Request;

class KardexService
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    private const IN_TYPES = [
        'purchase',
        'sale_return',
        'adjustment_in',
        'transfer_in',
        'transfer_request_in',
        'return_in',
        'released',
    ];

    private const OUT_TYPES = [
        'purchase_return',
        'sale',
        'adjustment_out',
        'transfer_out',
        'transfer_request_out',
        'return_out',
        'damaged',
        'reserved',
    ];

    public function product(Product $product, array $filters = [], ?Request $request = null): array
    {
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $variantId = isset($filters['product_variant_id']) ? (int) $filters['product_variant_id'] : null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $movements = $this->applyUserScope(
            $this->signedMovements($product, $warehouseId, $variantId),
            $request,
        )
            ->with(['warehouse', 'product', 'variant'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Saldo real actual: lo que queda del producto (o de la variante
        // seleccionada) en el almacen. Es la fuente de verdad para la columna
        // Saldo: la cantidad que queda, no un acumulado que puede divergir.
        $realBalance = $this->realBalance($product, $warehouseId, $variantId);

        // Reconstruir el saldo hacia atras desde el stock real: iteramos de la
        // fila mas reciente a la mas antigua. El running_balance de cada fila
        // es el saldo DESPUES de ese movimiento; el stock real es el saldo
        // despues del movimiento mas reciente.
        $runningBalance = (float) $realBalance;
        $balanced = [];
        foreach ($movements->reverse() as $movement) {
            $balanced[(int) $movement->id] = round($runningBalance, 4);
            $runningBalance -= $this->signedQuantity($movement);
        }

        // Aplicar rango de fechas sobre la lista ya balanceada.
        $inRange = $movements
            ->when($dateFrom, fn ($collection) => $collection->filter(
                fn (StockMovement $m): bool => $m->created_at && $m->created_at->toDateString() >= $dateFrom
            ))
            ->when($dateTo, fn ($collection) => $collection->filter(
                fn (StockMovement $m): bool => $m->created_at && $m->created_at->toDateString() <= $dateTo
            ))
            ->values();

        // opening_balance = stock antes del primer movimiento del rango.
        $openingBalance = $inRange->isEmpty()
            ? $realBalance
            : (float) ($balanced[(int) $inRange->first()->id] ?? $realBalance) - $this->signedQuantity($inRange->first());

        $result = $inRange->map(function (StockMovement $movement) use ($balanced): array {
            $quantityIn = $this->quantityIn($movement);
            $quantityOut = $this->quantityOut($movement);

            return [
                'id' => $movement->id,
                'date' => $movement->created_at?->toISOString(),
                'warehouse_id' => $movement->warehouse_id,
                'warehouse_name' => $movement->warehouse?->name,
                'product_id' => $movement->product_id,
                'product_name' => $movement->product?->name,
                'product_variant_id' => $movement->product_variant_id,
                'product_variant' => $movement->variant ? [
                    'id' => $movement->variant->id,
                    'color' => $movement->variant->color,
                    'sku_variant' => $movement->variant->sku_variant,
                ] : null,
                'type' => $movement->type,
                'quantity_in' => round($quantityIn, 4),
                'quantity_out' => round($quantityOut, 4),
                'running_balance' => $balanced[(int) $movement->id] ?? 0.0,
                'unit_cost' => request()->user()?->can('finance.costs.view')
                    ? ($movement->unit_cost === null ? null : (float) $movement->unit_cost)
                    : null,
                'reason' => $movement->reason,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
            ];
        });

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'opening_balance' => $openingBalance,
            'closing_balance' => $inRange->isEmpty() ? $openingBalance : ($balanced[(int) $inRange->last()->id] ?? $openingBalance),
            'movements' => $result->values()->all(),
        ];
    }

    private function signedMovements(Product $product, ?int $warehouseId, ?int $variantId = null)
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($variantId, fn ($query) => $query->where('product_variant_id', $variantId));
    }

    /**
     * Stock real disponible del producto (o de la variante) en el almacen.
     * Es la fuente de verdad para el saldo del kardex.
     */
    private function realBalance(Product $product, ?int $warehouseId, ?int $variantId): float
    {
        $query = StockBalance::query()
            ->where('product_id', $product->id)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId));

        return (float) $query->sum('quantity_available');
    }

    /**
     * Aplica el scope del user actual a la query de movimientos antes de ejecutar.
     * Llamar desde product() antes de obtener opening balance y movements.
     */
    private function applyUserScope($query, ?Request $request)
    {
        if ($request === null) {
            return $query;
        }
        $user = $request->user();
        if (! $user) {
            return $query;
        }

        return $this->scopes->applyWarehouseScope($query, $user, 'warehouse_id');
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
