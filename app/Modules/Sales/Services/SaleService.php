<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private readonly ProductPriceService $prices,
        private readonly InventoryMovementService $inventory,
    ) {
    }

    public function createDraft(User $user, array $items, ?int $customerId = null): Sale
    {
        return DB::transaction(function () use ($user, $items, $customerId): Sale {
            $sale = Sale::create([
                'status' => Sale::STATUS_DRAFT,
                'customer_id' => $customerId,
                'created_by' => $user->id,
            ]);

            $totalBase = 0.0;
            $totalLocal = 0.0;

            foreach ($items as $item) {
                $warehouse = Warehouse::query()->findOrFail($item['warehouse_id']);
                $product = Product::query()->with('warrantyPolicy')->findOrFail($item['product_id']);
                $quantity = (float) $item['quantity'];

                if ($quantity <= 0) {
                    throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
                }

                $quote = $this->prices->quote($product);
                $baseUnitPrice = (float) $quote['base_price_usd'];
                $baseTotal = round($baseUnitPrice * $quantity, 4);
                $unitPrice = (float) $quote['sale_price'];
                $totalAmount = round($unitPrice * $quantity, 4);
                $localTotal = $quote['price_ves'] === null ? 0.0 : round((float) $quote['price_ves'] * $quantity, 4);

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'product_unit_ids' => ($item['product_unit_ids'] ?? []) ?: null,
                    'sale_currency' => $quote['sale_currency'],
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalAmount,
                    'base_unit_price' => $baseUnitPrice,
                    'base_total_amount' => $baseTotal,
                    'exchange_rate_type_id' => $quote['exchange_rate_type_id'],
                    'exchange_rate_type_code' => $quote['exchange_rate_type_code'],
                    'exchange_rate' => $quote['exchange_rate'],
                    'warranty_policy_id' => $product->warrantyPolicy?->id,
                    'warranty_policy_name' => $product->warrantyPolicy?->name,
                    'warranty_duration_days' => $product->warrantyPolicy?->duration_days,
                    'warranty_coverage_type' => $product->warrantyPolicy?->coverage_type,
                    'warranty_conditions' => $product->warrantyPolicy?->conditions,
                ]);

                $totalBase += $baseTotal;
                $totalLocal += $localTotal;
            }

            $sale->update([
                'total_base_amount' => $totalBase,
                'total_local_amount' => $totalLocal,
            ]);

            return $sale->refresh()->load(['customer', 'items.product', 'items.warehouse']);
        });
    }

    public function confirm(Sale $sale, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $user): Sale {
            $sale = Sale::query()->with(['items.product', 'items.warehouse'])->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== Sale::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Solo se pueden confirmar ventas en borrador.']);
            }

            foreach ($sale->items as $item) {
                $productUnits = $this->validatedProductUnitsForSaleItem($item);

                try {
                    $movement = $this->inventory->sale(
                        warehouse: $item->warehouse,
                        product: $item->product,
                        quantity: (float) $item->quantity,
                        createdBy: $user,
                        reason: "Venta #{$sale->id}",
                        referenceType: Sale::class,
                        referenceId: $sale->id,
                    );
                } catch (InsufficientStockException) {
                    throw ValidationException::withMessages([
                        'stock' => "Stock insuficiente para el producto {$item->product_id}.",
                    ]);
                }

                $item->update(['stock_movement_id' => $movement->id]);
                $this->markProductUnitsAsSold($productUnits, $movement->id);
            }

            $confirmedAt = now();

            $sale->update([
                'status' => Sale::STATUS_CONFIRMED,
                'confirmed_at' => $confirmedAt,
            ]);

            foreach ($sale->items as $item) {
                if ($item->warranty_policy_id === null) {
                    continue;
                }

                $item->update([
                    'warranty_starts_at' => $confirmedAt,
                    'warranty_expires_at' => $item->warranty_duration_days === null
                        ? null
                        : $confirmedAt->copy()->addDays((int) $item->warranty_duration_days),
                ]);
            }

            app(AccountsReceivableService::class)->createForSale($sale->refresh());

            return $sale->refresh()->load(['customer', 'items.product', 'items.warehouse', 'items.stockMovement']);
        });
    }

    public function cancelDraft(Sale $sale): Sale
    {
        if ($sale->status !== Sale::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Solo se pueden cancelar ventas en borrador en esta fase.']);
        }

        $sale->update([
            'status' => Sale::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        return $sale->refresh()->load(['customer', 'items.product', 'items.warehouse']);
    }

    private function validatedProductUnitsForSaleItem(SaleItem $item): array
    {
        $unitIds = $item->product_unit_ids ?? [];

        if (! $item->product->requiresSerializedTracking()) {
            if ($unitIds !== []) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Solo los productos serializados pueden vender IMEIs o seriales especificos.',
                ]);
            }

            return [];
        }

        if ((float) $item->quantity !== floor((float) $item->quantity) || count($unitIds) !== (int) $item->quantity) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Los productos serializados requieren un IMEI o serial por cada unidad vendida.',
            ]);
        }

        if (count($unitIds) !== count(array_unique($unitIds))) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'No se puede repetir el mismo IMEI o serial en una venta.',
            ]);
        }

        $units = ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($units->count() !== count($unitIds)) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Uno o mas IMEIs no existen en la empresa actual.',
            ]);
        }

        foreach ($unitIds as $unitId) {
            $unit = $units->get($unitId);

            if ((int) $unit->product_id !== (int) $item->product_id) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Uno o mas IMEIs no pertenecen al producto vendido.',
                ]);
            }

            if ((int) $unit->warehouse_id !== (int) $item->warehouse_id) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Uno o mas IMEIs no estan en el almacen de la venta.',
                ]);
            }

            if ($unit->status !== ProductUnit::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Uno o mas IMEIs ya no estan disponibles para vender.',
                ]);
            }
        }

        return $units->values()->all();
    }

    private function markProductUnitsAsSold(array $productUnits, int $movementId): void
    {
        foreach ($productUnits as $unit) {
            $unit->update([
                'status' => ProductUnit::STATUS_SOLD,
                'released_stock_movement_id' => $movementId,
            ]);
        }
    }
}
