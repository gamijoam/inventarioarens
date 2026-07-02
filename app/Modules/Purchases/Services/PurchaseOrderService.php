<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(private readonly InventoryMovementService $inventory)
    {
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

            return $purchaseOrder->refresh()->load(['supplier', 'items.product', 'items.warehouse']);
        });
    }

    public function receive(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $user): PurchaseOrder {
            $purchaseOrder = PurchaseOrder::query()
                ->with(['items.product', 'items.warehouse'])
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);

            if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se pueden recibir compras en borrador.',
                ]);
            }

            foreach ($purchaseOrder->items as $item) {
                $movement = $this->inventory->purchase(
                    warehouse: $item->warehouse,
                    product: $item->product,
                    quantity: (float) $item->quantity,
                    unitCost: (float) $item->base_unit_cost,
                    createdBy: $user,
                    reason: "Compra #{$purchaseOrder->id}",
                    referenceType: PurchaseOrder::class,
                    referenceId: $purchaseOrder->id,
                );

                $item->update(['stock_movement_id' => $movement->id]);

                $this->createProductUnits($item, $movement->id);
            }

            $purchaseOrder->update([
                'status' => PurchaseOrder::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            return $purchaseOrder->refresh()->load(['supplier', 'items.product', 'items.warehouse', 'items.stockMovement']);
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

    private function createProductUnits(PurchaseItem $item, int $movementId): void
    {
        foreach ($item->serial_units ?? [] as $serialUnit) {
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
