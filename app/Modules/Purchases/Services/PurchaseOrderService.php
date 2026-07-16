<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        private readonly InventoryMovementService $inventory,
        private readonly InventoryValuationService $valuation,
        private readonly SyncCatalogOutboxService $syncCatalog,
    ) {
    }

    public function createDraft(User $user, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($user, $data): PurchaseOrder {
            $currency = $data['purchase_currency'];
            [$rateType, $exchangeRate] = $this->rateSnapshot($currency, $data['exchange_rate_type_id'] ?? null);

            $purchaseOrder = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'] ?? null,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'document_number' => $data['document_number'] ?? null,
                'issued_at' => $data['issued_at'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'purchase_currency' => $currency,
                'exchange_rate_type_id' => $rateType?->id,
                'exchange_rate_type_code' => $rateType?->code,
                'exchange_rate' => $exchangeRate,
                'created_by' => $user->id,
            ]);

            $totalBase = 0.0;
            $totalLocal = 0.0;

            foreach ($data['items'] as $item) {
                $warehouse = Warehouse::query()->findOrFail($item['warehouse_id']);
                $product = Product::query()->findOrFail($item['product_id']);
                $quantity = (float) $item['quantity'];
                $serialUnits = $item['serial_units'] ?? [];
                $this->validateSerialUnits($product, $quantity, $serialUnits);
                $unitCost = (float) $item['unit_cost'];
                $totalCost = round($unitCost * $quantity, 4);
                $baseUnitCost = $this->baseUnitCost($currency, $unitCost, $exchangeRate);
                $baseTotalCost = round($baseUnitCost * $quantity, 4);

                PurchaseItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'base_unit_cost' => $baseUnitCost,
                    'base_total_cost' => $baseTotalCost,
                    'serial_units' => $serialUnits ?: null,
                ]);

                $totalBase += $baseTotalCost;
                $totalLocal += $currency === PurchaseOrder::CURRENCY_VES
                    ? $totalCost
                    : ($exchangeRate === null ? 0.0 : round($totalCost * $exchangeRate, 4));
            }

            $purchaseOrder->update([
                'total_base_amount' => $totalBase,
                'total_local_amount' => $totalLocal,
            ]);

            $po = $purchaseOrder->refresh()->load(['supplier', 'items.product', 'items.warehouse']);

            // Emitir evento de sync para que la nube tenga visibilidad del
            // borrador (efecto real sobre stock ocurre en receive()).
            $this->syncCatalog->purchaseOrderCreated($po);

            return $po;
        });
    }

    public function receive(PurchaseOrder $purchaseOrder, User $user, array $data = []): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $user, $data): PurchaseOrder {
            $purchaseOrder = PurchaseOrder::query()
                ->with(['items.product', 'items.warehouse'])
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);

            if (! in_array($purchaseOrder->status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se pueden recibir compras en borrador o parcialmente recibidas.',
                ]);
            }

            $receipts = $this->receiptItems($purchaseOrder, $data['items'] ?? null);
            $priceReviewItems = [];
            $priceReviewThreshold = (float) config('inventory.price_review_threshold', 5);

            foreach ($receipts as $receipt) {
                /** @var PurchaseItem $item */
                $item = $receipt['item'];
                $quantity = (float) $receipt['quantity'];
                $serialUnits = $receipt['serial_units'];

                $this->validateReceiptQuantity($item, $quantity);
                $this->validateSerialUnits($item->product, $quantity, $serialUnits);

                $movement = $this->inventory->purchase(
                    warehouse: $item->warehouse,
                    product: $item->product,
                    quantity: $quantity,
                    unitCost: (float) $item->base_unit_cost,
                    createdBy: $user,
                    reason: "Compra #{$purchaseOrder->id}",
                    referenceType: PurchaseOrder::class,
                    referenceId: $purchaseOrder->id,
                );

                $item->update(['stock_movement_id' => $movement->id]);
                $item->received_quantity = round((float) $item->received_quantity + $quantity, 4);
                $item->save();

                $this->createProductUnits($item, $movement->id, $serialUnits);

                $previousWac = $item->product->average_cost === null ? null : (float) $item->product->average_cost;
                $previousBasePrice = $item->product->base_price === null ? null : (float) $item->product->base_price;
                $previousMargin = $item->product->profit_margin === null ? null : (float) $item->product->profit_margin;
                $newUnitCost = (float) $item->base_unit_cost;

                // Recalcular WAC del producto tras cada item recibido para que
                // `products.average_cost` refleje el costo actualizado. Idempotente
                // y O(N movimientos) por producto, suficiente para compras normales.
                // Si el volumen crece, mover a un Job en cola.
                $this->valuation->recalculate($item->product);
                $item->product->refresh();

                if (
                    $previousWac !== null
                    && $previousWac > 0
                    && $previousMargin !== null
                    && abs((($newUnitCost - $previousWac) / $previousWac) * 100) >= $priceReviewThreshold
                ) {
                    $priceReviewItems[] = [
                        'item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product->name,
                        'previous_wac' => round($previousWac, 4),
                        'previous_base_price' => $previousBasePrice,
                        'new_unit_cost' => round($newUnitCost, 4),
                        'profit_margin' => $previousMargin,
                    ];
                }

                // Recalcular el base_price automaticamente si el producto tiene
                // margen definido. Asi el precio de venta refleja el nuevo WAC
                // sin esperar a que el usuario abra el PriceReviewDialog.
                $newWac = $item->product->average_cost === null ? null : (float) $item->product->average_cost;
                if ($newWac !== null && $item->product->profit_margin !== null) {
                    $newBase = round($newWac * (1 + ((float) $item->product->profit_margin / 100)), 2);
                    if ((float) $item->product->base_price !== $newBase) {
                        $item->product->base_price = $newBase;
                        $item->product->save();
                    }
                }
            }

            [$receivedBase, $receivedLocal] = $this->receivedTotals($purchaseOrder->refresh()->load('items'));
            $allReceived = $purchaseOrder->items->every(
                fn (PurchaseItem $item): bool => (float) $item->received_quantity >= (float) $item->quantity
            );

            $purchaseOrder->update([
                'status' => $allReceived ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                'received_base_amount' => $receivedBase,
                'received_local_amount' => $receivedLocal,
                'received_at' => $allReceived ? ($data['received_at'] ?? now()) : null,
            ]);

            app(AccountsPayableService::class)->createForPurchase($purchaseOrder->refresh());

            $po = $purchaseOrder->refresh()->load(['supplier', 'items.product', 'items.warehouse', 'items.stockMovement']);
            $po->setAttribute('price_review_items', $priceReviewItems);

            // Emitir evento de sync para que la nube cree la entrada de stock
            // correspondiente. Solo emite items que efectivamente se recibieron
            // (los que tienen stock_movement_id), asi una recepcion parcial
            // solo sincroniza lo que realmente entro a bodega.
            $this->syncCatalog->purchaseOrderReceived($po);

            return $po;
        });
    }

    public function cancelDraft(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden cancelar compras en borrador en esta fase.',
            ]);
        }

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        return $purchaseOrder->refresh()->load(['supplier', 'items.product', 'items.warehouse']);
    }

    private function rateSnapshot(string $currency, ?int $rateTypeId): array
    {
        if ($currency === PurchaseOrder::CURRENCY_USD) {
            $rateType = $rateTypeId ? ExchangeRateType::query()->findOrFail($rateTypeId) : null;
            $rate = $rateType ? $this->activeRateFor($rateType) : null;

            return [$rateType, $rate ? (float) $rate->rate : null];
        }

        $rateType = $rateTypeId
            ? ExchangeRateType::query()->findOrFail($rateTypeId)
            : ExchangeRateType::query()->where('is_default', true)->where('is_active', true)->first();

        if (! $rateType) {
            throw ValidationException::withMessages([
                'exchange_rate_type_id' => 'La compra en bolivares requiere un tipo de tasa activo.',
            ]);
        }

        $rate = $this->activeRateFor($rateType);

        if (! $rate) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'La compra en bolivares requiere una tasa activa.',
            ]);
        }

        return [$rateType, (float) $rate->rate];
    }

    private function activeRateFor(ExchangeRateType $rateType): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('exchange_rate_type_id', $rateType->id)
            ->where('base_currency', ExchangeRate::BASE_USD)
            ->where('quote_currency', ExchangeRate::QUOTE_VES)
            ->where('is_active', true)
            ->latest('effective_at')
            ->first();
    }

    private function baseUnitCost(string $currency, float $unitCost, ?float $exchangeRate): float
    {
        if ($currency === PurchaseOrder::CURRENCY_USD) {
            return round($unitCost, 4);
        }

        if (! $exchangeRate || $exchangeRate <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'La tasa usada debe ser mayor que cero.',
            ]);
        }

        return round($unitCost / $exchangeRate, 4);
    }

    private function receiptItems(PurchaseOrder $purchaseOrder, ?array $requestedItems): array
    {
        if ($requestedItems === null) {
            return $purchaseOrder->items
                ->map(function (PurchaseItem $item): ?array {
                    $pending = round((float) $item->quantity - (float) $item->received_quantity, 4);

                    if ($pending <= 0) {
                        return null;
                    }

                    return [
                        'item' => $item,
                        'quantity' => $pending,
                        'serial_units' => $item->serial_units ?? [],
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        $itemsById = $purchaseOrder->items->keyBy('id');

        return collect($requestedItems)
            ->map(function (array $receipt) use ($itemsById): array {
                $item = $itemsById->get($receipt['purchase_item_id']);

                if (! $item) {
                    throw ValidationException::withMessages([
                        'items' => 'El item indicado no pertenece a esta compra.',
                    ]);
                }

                return [
                    'item' => $item,
                    'quantity' => (float) $receipt['quantity'],
                    'serial_units' => $receipt['serial_units'] ?? [],
                ];
            })
            ->all();
    }

    private function validateReceiptQuantity(PurchaseItem $item, float $quantity): void
    {
        $pending = round((float) $item->quantity - (float) $item->received_quantity, 4);

        if ($quantity <= 0 || $quantity > $pending) {
            throw ValidationException::withMessages([
                'items' => 'La cantidad recibida no puede superar la cantidad pendiente del item.',
            ]);
        }
    }

    private function receivedTotals(PurchaseOrder $purchaseOrder): array
    {
        $receivedBase = 0.0;
        $receivedLocal = 0.0;

        foreach ($purchaseOrder->items as $item) {
            $quantity = (float) $item->received_quantity;
            $receivedBase += round((float) $item->base_unit_cost * $quantity, 4);
            $receivedLocal += $purchaseOrder->purchase_currency === PurchaseOrder::CURRENCY_VES
                ? round((float) $item->unit_cost * $quantity, 4)
                : ($purchaseOrder->exchange_rate === null ? 0.0 : round((float) $item->unit_cost * $quantity * (float) $purchaseOrder->exchange_rate, 4));
        }

        return [round($receivedBase, 4), round($receivedLocal, 4)];
    }

    private function validateSerialUnits(Product $product, float $quantity, array $serialUnits): void
    {
        if (! $product->requiresSerializedTracking()) {
            if ($serialUnits !== []) {
                throw ValidationException::withMessages([
                    'serial_units' => 'Solo los productos serializados pueden recibir IMEIs o seriales.',
                ]);
            }

            return;
        }

        if (count($serialUnits) !== (int) $quantity || $quantity !== floor($quantity)) {
            throw ValidationException::withMessages([
                'serial_units' => 'Los productos serializados requieren un serial por cada unidad entera comprada.',
            ]);
        }

        $seen = [];

        foreach ($serialUnits as $serialUnit) {
            $key = "{$serialUnit['serial_type']}:{$serialUnit['serial_number']}";

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'serial_units' => 'No se pueden repetir seriales dentro de la misma compra.',
                ]);
            }

            $seen[$key] = true;

            if (ProductUnit::query()
                ->where('serial_type', $serialUnit['serial_type'])
                ->where('serial_number', $serialUnit['serial_number'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'serial_units' => "El serial {$serialUnit['serial_number']} ya existe en la empresa actual.",
                ]);
            }
        }
    }

    private function createProductUnits(PurchaseItem $item, int $movementId, ?array $serialUnits = null): void
    {
        foreach ($serialUnits ?? $item->serial_units ?? [] as $serialUnit) {
            ProductUnit::create([
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'serial_type' => $serialUnit['serial_type'],
                'serial_number' => $serialUnit['serial_number'],
                'status' => ProductUnit::STATUS_AVAILABLE,
                'acquired_stock_movement_id' => $movementId,
            ]);
        }
    }
}
