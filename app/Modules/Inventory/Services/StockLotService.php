<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockLotMovement;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Sync\Services\SyncOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Existencia por lote y vencimiento de los productos que controlan
 * vencimiento. Las salidas se imputan solas: primero la existencia anterior
 * al control de lotes, luego los lotes vigentes del que vence antes al que
 * vence despues y, al final, los ya vencidos.
 */
class StockLotService
{
    public const CONSUMING_TYPES = [
        'sale',
        'purchase_return',
        'adjustment_out',
        'transfer_out',
        'transfer_request_out',
        'return_out',
        'damaged',
    ];

    private const TOLERANCE = 0.0001;

    private ?int $forcedLotId = null;

    public function __construct(private readonly SyncOutboxService $syncOutbox) {}

    public function tracks(Product $product): bool
    {
        return (bool) $product->tracks_expiration;
    }

    /**
     * Entrada de compra con su lote y fecha de vencimiento.
     */
    public function receive(StockMovement $movement, ?string $lotCode, string $expiresOn, ?float $unitCost): StockLot
    {
        $lot = $this->lockOrCreateLot(
            (int) $movement->warehouse_id,
            (int) $movement->product_id,
            $movement->product_variant_id === null ? null : (int) $movement->product_variant_id,
            $lotCode,
            $expiresOn,
            $unitCost,
        );

        $this->move($lot, $movement, (float) $movement->quantity);

        return $lot;
    }

    /**
     * Imputa una salida a los lotes. La parte que excede lo que esta en lotes
     * corresponde a existencia sin lote y no se registra.
     */
    public function consume(StockMovement $movement): void
    {
        if (! in_array($movement->type, self::CONSUMING_TYPES, true)) {
            return;
        }

        $product = Product::query()->find($movement->product_id);

        if (! $product || ! $this->tracks($product)) {
            return;
        }

        $quantity = (float) $movement->quantity;
        $variantId = $movement->product_variant_id === null ? null : (int) $movement->product_variant_id;
        $lots = $this->lotsQuery((int) $movement->warehouse_id, (int) $movement->product_id, $variantId)
            ->where('quantity', '>', 0)
            ->lockForUpdate()
            ->get();

        if ($this->forcedLotId !== null) {
            $lots = $lots->where('id', $this->forcedLotId)->values();
        } else {
            $balance = StockBalance::query()
                ->where('warehouse_id', $movement->warehouse_id)
                ->where('product_id', $movement->product_id)
                ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'), fn ($q) => $q->where('product_variant_id', $variantId))
                ->first();
            $physicalBefore = (float) ($balance?->quantity_available ?? 0) + (float) ($balance?->quantity_reserved ?? 0) + $quantity;
            $withoutLot = max(0.0, $physicalBefore - (float) $lots->sum('quantity'));
            $quantity -= min($quantity, $withoutLot);
            $today = now()->toDateString();
            $lots = $lots->sortBy(fn (StockLot $lot): string => ($lot->expires_on->toDateString() < $today ? '1' : '0').$lot->expires_on->toDateString().'|'.str_pad((string) $lot->id, 12, '0', STR_PAD_LEFT))->values();
        }

        foreach ($lots as $lot) {
            if ($quantity <= self::TOLERANCE) {
                break;
            }

            $taken = min($quantity, (float) $lot->quantity);
            $this->move($lot, $movement, -$taken);
            $quantity -= $taken;
        }
    }

    /**
     * La entrada de un traslado recibe los mismos lotes que tomo su salida.
     */
    public function mirror(StockMovement $out, StockMovement $in): void
    {
        $remaining = (float) $in->quantity;
        $allocations = StockLotMovement::withoutGlobalScopes()
            ->with(['lot' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('stock_movement_id', $out->id)
            ->where('quantity', '<', 0)
            ->orderBy('id')
            ->get();

        foreach ($allocations as $allocation) {
            if ($remaining <= self::TOLERANCE || ! $allocation->lot) {
                break;
            }

            $quantity = min($remaining, abs((float) $allocation->quantity));
            $lot = $this->lockOrCreateLot(
                (int) $in->warehouse_id,
                (int) $in->product_id,
                $in->product_variant_id === null ? null : (int) $in->product_variant_id,
                $allocation->lot->lot_code,
                $allocation->lot->expires_on->toDateString(),
                $allocation->lot->unit_cost === null ? null : (float) $allocation->lot->unit_cost,
            );
            $this->move($lot, $in, $quantity);
            $remaining -= $quantity;
        }
    }

    /**
     * Una devolucion de venta vuelve a los lotes de los que salio la venta.
     */
    public function restore(?int $sourceMovementId, StockMovement $in): void
    {
        if ($sourceMovementId === null) {
            return;
        }

        $remaining = (float) $in->quantity;
        $allocations = StockLotMovement::query()
            ->with('lot')
            ->where('stock_movement_id', $sourceMovementId)
            ->where('quantity', '<', 0)
            ->orderBy('id')
            ->get();

        foreach ($allocations as $allocation) {
            if ($remaining <= self::TOLERANCE || ! $allocation->lot) {
                break;
            }

            $quantity = min($remaining, abs((float) $allocation->quantity));
            $lot = StockLot::query()->lockForUpdate()->findOrFail($allocation->stock_lot_id);
            $this->move($lot, $in, $quantity);
            $remaining -= $quantity;
        }
    }

    /**
     * Da de baja (merma) un lote concreto, valorado a su costo.
     */
    public function writeOff(StockLot $lot, User $user, ?float $quantity, ?string $reason): StockMovement
    {
        return DB::transaction(function () use ($lot, $user, $quantity, $reason): StockMovement {
            $lot = StockLot::query()->with(['product', 'warehouse'])->lockForUpdate()->findOrFail($lot->id);
            $available = (float) $lot->quantity;
            $quantity ??= $available;

            if ($quantity <= 0.0 || $quantity > $available + self::TOLERANCE) {
                throw ValidationException::withMessages([
                    'quantity' => sprintf('La cantidad a dar de baja debe ser mayor que cero y no superar lo que queda en el lote (%s).', rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.')),
                ]);
            }

            $this->forcedLotId = $lot->id;

            try {
                $movement = app(InventoryMovementService::class)->adjustmentOut(
                    warehouse: $lot->warehouse,
                    product: $lot->product,
                    quantity: $quantity,
                    createdBy: $user,
                    reason: trim('Merma por vencimiento: '.($reason ?: 'lote '.($lot->lot_code ?? $lot->expires_on->toDateString()))),
                    referenceType: StockLot::class,
                    referenceId: $lot->id,
                    productVariantId: $lot->product_variant_id === null ? null : (int) $lot->product_variant_id,
                    unitCost: $lot->unit_cost === null ? null : (float) $lot->unit_cost,
                );
            } finally {
                $this->forcedLotId = null;
            }

            app(SyncCatalogOutboxService::class)->stockMovementCreated($movement);

            return $movement;
        });
    }

    private function lotsQuery(int $warehouseId, int $productId, ?int $variantId)
    {
        return StockLot::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'), fn ($q) => $q->where('product_variant_id', $variantId));
    }

    private function lockOrCreateLot(int $warehouseId, int $productId, ?int $variantId, ?string $lotCode, string $expiresOn, ?float $unitCost): StockLot
    {
        $lotCode = trim((string) $lotCode) === '' ? null : trim((string) $lotCode);
        $key = StockLot::keyFor($variantId, $lotCode, $expiresOn);
        $lot = StockLot::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('lot_key', $key)
            ->lockForUpdate()
            ->first();

        if ($lot) {
            if ($lot->unit_cost === null && $unitCost !== null) {
                $lot->unit_cost = $unitCost;
            }

            return $lot;
        }

        return StockLot::create([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'lot_code' => $lotCode,
            'expires_on' => $expiresOn,
            'lot_key' => $key,
            'quantity' => 0,
            'unit_cost' => $unitCost,
            'received_at' => now(),
        ]);
    }

    private function move(StockLot $lot, StockMovement $movement, float $quantity): void
    {
        $lot->quantity = round((float) $lot->quantity + $quantity, 4);
        $lot->save();

        StockLotMovement::create([
            'stock_lot_id' => $lot->id,
            'stock_movement_id' => $movement->id,
            'quantity' => round($quantity, 4),
        ]);

        $this->recordSyncEvent($lot->refresh()->load(['product', 'warehouse', 'variant']));
    }

    private function recordSyncEvent(StockLot $lot): void
    {
        $this->syncOutbox->record(
            eventType: 'stock_lot.updated',
            aggregateType: 'stock_lot',
            aggregateId: $lot->id,
            payload: [
                'lot_id' => $lot->id,
                'sku' => $lot->product?->sku,
                'warehouse_code' => $lot->warehouse?->code,
                'product_variant_sku' => $lot->variant?->sku_variant,
                'product_variant_color' => $lot->variant?->color,
                'lot_code' => $lot->lot_code,
                'expires_on' => $lot->expires_on->toDateString(),
                'quantity' => (string) $lot->quantity,
                'unit_cost' => $lot->unit_cost === null ? null : (string) $lot->unit_cost,
                'updated_at' => $lot->updated_at?->toJSON(),
            ],
            idempotencyKey: "stock_lot.updated:stock_lot:{$lot->id}:".$lot->quantity.':'.microtime(true)
        );
    }
}
