<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Fiscal\Services\FiscalSaleTaxService;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Promotions\Models\SalePromotionApplication;
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
        private readonly FiscalSaleTaxService $fiscalTaxes,
    ) {}

    public function createDraft(User $user, array $items, ?int $customerId = null): Sale
    {
        return DB::transaction(function () use ($user, $items, $customerId): Sale {
            $sale = Sale::create([
                'status' => Sale::STATUS_DRAFT,
                'customer_id' => $customerId,
                'created_by' => $user->id,
            ]);

            $createdItems = [];
            $fiscalLines = [];

            foreach ($items as $itemIndex => $item) {
                $warehouse = Warehouse::query()->findOrFail($item['warehouse_id']);
                $product = Product::query()->with(['warrantyPolicy', 'fiscalTaxRate'])->findOrFail($item['product_id']);
                $quantity = (float) $item['quantity'];

                if ($quantity <= 0) {
                    throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
                }

                $quote = $this->prices->quote(
                    $product,
                    $item['price_list_id'] ?? null,
                    $item['price_source'] ?? null,
                );
                $baseUnitPrice = (float) $quote['base_price_usd'];
                $normalBaseTotal = round($baseUnitPrice * $quantity, 4);
                $normalUnitPrice = (float) $quote['sale_price'];
                $normalTotalAmount = round($normalUnitPrice * $quantity, 4);
                $normalLocalTotal = $quote['price_ves'] === null ? 0.0 : round((float) $quote['price_ves'] * $quantity, 4);
                $promotionApplied = array_key_exists('promotion_base_total_amount', $item);
                $baseTotal = $promotionApplied ? (float) $item['promotion_base_total_amount'] : $normalBaseTotal;
                $totalAmount = $promotionApplied ? (float) $item['promotion_total_amount'] : $normalTotalAmount;
                $localTotal = $promotionApplied ? (float) $item['promotion_local_total_amount'] : $normalLocalTotal;
                $discount = $promotionApplied
                    ? [
                        'type' => null,
                        'value' => 0,
                        'amount' => 0,
                        'base_amount' => 0,
                        'local_amount' => 0,
                        'reason' => null,
                    ]
                    : $this->resolveLineDiscount($item, $quote, $totalAmount, $baseTotal, $localTotal);
                $netTotalAmount = $promotionApplied ? $totalAmount : round($totalAmount - $discount['amount'], 4);
                $netBaseTotal = $promotionApplied ? $baseTotal : round($baseTotal - $discount['base_amount'], 4);
                $netLocalTotal = $promotionApplied ? $localTotal : round($localTotal - $discount['local_amount'], 4);
                $fiscalTaxRateId = isset($item['_fiscal_tax_rate_id'])
                    ? (int) $item['_fiscal_tax_rate_id']
                    : null;
                $fiscalTaxRate = $this->fiscalTaxes->resolveRateForProduct($product, $fiscalTaxRateId);
                $fiscal = $this->fiscalTaxes->calculateProduct($product, $netBaseTotal, $netLocalTotal, $fiscalTaxRate);
                $fiscalLines[] = $fiscal;

                $createdItems[$itemIndex] = SaleItem::create([
                    'sale_id' => $sale->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'price_list_id' => $quote['price_list_id'],
                    'price_list_name' => $quote['price_list_name'],
                    'quantity' => $quantity,
                    'product_unit_ids' => ($item['product_unit_ids'] ?? []) ?: null,
                    'sale_currency' => $quote['sale_currency'],
                    'unit_price' => round($netTotalAmount / $quantity, 4),
                    'total_amount' => $netTotalAmount,
                    'base_unit_price' => $baseUnitPrice,
                    'base_total_amount' => $netBaseTotal,
                    'local_total_amount' => $netLocalTotal,
                    ...$fiscal,
                    'fiscal_tax_source' => $fiscalTaxRateId === null ? 'product' : 'promotion_override',
                    'fiscal_tax_override_code' => $fiscalTaxRateId === null ? null : $fiscalTaxRate?->code,
                    'discount_type' => $discount['type'],
                    'discount_value' => $discount['value'],
                    'discount_amount' => $discount['amount'],
                    'discount_base_amount' => $discount['base_amount'],
                    'discount_local_amount' => $discount['local_amount'],
                    'discount_reason' => $discount['reason'],
                    'promotion_id' => $item['promotion_id'] ?? null,
                    'promotion_code' => $item['promotion_code'] ?? null,
                    'promotion_name' => $item['promotion_name'] ?? null,
                    'promotion_benefit_type' => $item['promotion_benefit_type'] ?? null,
                    'promotion_price_usd' => $item['promotion_price_usd'] ?? null,
                    'promotion_discount_percent' => $item['promotion_discount_percent'] ?? null,
                    'promotion_discount_amount_usd' => $item['promotion_discount_amount_usd'] ?? null,
                    'promotion_adjustment_base_amount' => $item['promotion_adjustment_base_amount'] ?? 0,
                    'promotion_adjustment_local_amount' => $item['promotion_adjustment_local_amount'] ?? 0,
                    'exchange_rate_type_id' => $quote['exchange_rate_type_id'],
                    'exchange_rate_type_code' => $quote['exchange_rate_type_code'],
                    'exchange_rate' => $quote['exchange_rate'],
                    'warranty_policy_id' => $product->warrantyPolicy?->id,
                    'warranty_policy_name' => $product->warrantyPolicy?->name,
                    'warranty_duration_days' => $product->warrantyPolicy?->duration_days,
                    'warranty_coverage_type' => $product->warrantyPolicy?->coverage_type,
                    'warranty_conditions' => $product->warrantyPolicy?->conditions,
                ]);

            }

            $this->persistPromotionApplications($sale, $items, $createdItems);

            $fiscalTotals = $this->fiscalTaxes->aggregate($fiscalLines);
            $sale->update([
                'total_base_amount' => $fiscalTotals['fiscal_total_base_amount'],
                'total_local_amount' => $fiscalTotals['fiscal_total_local_amount'],
                ...$this->saleFiscalFields($fiscalTotals),
            ]);

            return $sale->refresh()->load(['customer', 'items.product.fiscalTaxRate', 'items.variant', 'items.warehouse']);
        });
    }

    public function recalculateFiscalTotals(Sale $sale, bool $freezeSnapshot = false): Sale
    {
        $sale->loadMissing('items.product.fiscalTaxRate');
        $fiscalLines = [];
        $snapshotAt = $freezeSnapshot ? now() : null;

        foreach ($sale->items as $item) {
            $overrideCode = $item->fiscal_tax_source === 'promotion_override'
                ? $item->fiscal_tax_override_code
                : null;
            $fiscalTaxRate = $this->fiscalTaxes->resolveRateForProduct($item->product, null, $overrideCode);
            $fiscal = $this->fiscalTaxes->calculateProduct(
                $item->product,
                $item->base_total_amount,
                $item->local_total_amount,
                $fiscalTaxRate,
            );
            $fiscalLines[] = $fiscal;
            $item->update([
                ...$fiscal,
                'fiscal_snapshot_at' => $snapshotAt,
            ]);
        }

        $fiscalTotals = $this->fiscalTaxes->aggregate($fiscalLines);
        $sale->update([
            'total_base_amount' => $fiscalTotals['fiscal_total_base_amount'],
            'total_local_amount' => $fiscalTotals['fiscal_total_local_amount'],
            ...$this->saleFiscalFields($fiscalTotals),
            'fiscal_snapshot_at' => $snapshotAt,
        ]);

        return $sale->refresh()->load(['customer', 'items.product.fiscalTaxRate', 'items.variant', 'items.warehouse']);
    }

    private function saleFiscalFields(array $fiscalTotals): array
    {
        return collect($fiscalTotals)
            ->except(['fiscal_total_base_amount', 'fiscal_total_local_amount'])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, SaleItem>  $createdItems
     */
    private function persistPromotionApplications(Sale $sale, array $items, array $createdItems): void
    {
        $grouped = [];
        foreach ($items as $itemIndex => $item) {
            foreach ($item['_promotion_allocations'] ?? [] as $allocation) {
                $grouped[$allocation['slot']][] = [$itemIndex, $allocation];
            }
        }

        foreach ($grouped as $slot => $entries) {
            $first = $entries[0][1];
            $application = SalePromotionApplication::create([
                'sale_id' => $sale->id,
                'promotion_id' => $first['promotion_id'],
                'slot' => $slot,
                'scope' => $first['scope'],
                'status' => $first['status'],
                'instance_uuid' => $first['instance_uuid'],
                'requested_by' => $first['requested_by'],
                'validated_by' => $first['validated_by'],
                'requested_at' => now(),
                'validated_at' => $first['validated_by'] ? now() : null,
                'promotion_code' => $first['promotion_code'],
                'promotion_name' => $first['promotion_name'],
                'benefit_type' => $first['benefit_type'],
                'payment_currency' => $first['payment_currency'],
                'price_usd' => $first['price_usd'],
                'discount_percent' => $first['discount_percent'],
                'discount_amount_usd' => $first['discount_amount_usd'],
                'conditions_snapshot' => $first['conditions_snapshot'],
                'base_before_amount' => collect($entries)->sum(fn (array $entry): float => (float) $entry[1]['base_before_amount']),
                'local_before_amount' => collect($entries)->sum(fn (array $entry): float => (float) $entry[1]['local_before_amount']),
                'base_adjustment_amount' => collect($entries)->sum(fn (array $entry): float => (float) $entry[1]['base_adjustment_amount']),
                'local_adjustment_amount' => collect($entries)->sum(fn (array $entry): float => (float) $entry[1]['local_adjustment_amount']),
                'base_after_amount' => collect($entries)->sum(fn (array $entry): float => (float) $entry[1]['base_after_amount']),
                'local_after_amount' => collect($entries)->sum(fn (array $entry): float => (float) $entry[1]['local_after_amount']),
            ]);

            foreach ($entries as [$itemIndex, $allocation]) {
                $application->items()->create([
                    'sale_item_id' => $createdItems[$itemIndex]->id,
                    'quantity' => $allocation['quantity'],
                    'base_before_amount' => $allocation['base_before_amount'],
                    'local_before_amount' => $allocation['local_before_amount'],
                    'base_adjustment_amount' => $allocation['base_adjustment_amount'],
                    'local_adjustment_amount' => $allocation['local_adjustment_amount'],
                    'base_after_amount' => $allocation['base_after_amount'],
                    'local_after_amount' => $allocation['local_after_amount'],
                ]);
            }
        }
    }

    public function confirm(Sale $sale, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $user): Sale {
            $sale = Sale::query()->with(['items.product.fiscalTaxRate', 'items.variant', 'items.warehouse'])->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== Sale::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Solo se pueden confirmar ventas en borrador.']);
            }

            $sale = $this->recalculateFiscalTotals($sale, true);

            foreach ($sale->items as $item) {
                $productUnits = $this->validatedProductUnitsForSaleItem($item);

                $movement = null;
                if ($item->product->track_stock) {
                    try {
                        $movement = $this->inventory->sale(
                            warehouse: $item->warehouse,
                            product: $item->product,
                            quantity: (float) $item->quantity,
                            unitCost: $item->base_unit_cost === null ? $item->product->last_purchase_cost : (float) $item->base_unit_cost,
                            createdBy: $user,
                            reason: "Venta #{$sale->id}",
                            referenceType: Sale::class,
                            referenceId: $sale->id,
                            productVariantId: $item->product_variant_id,
                        );
                    } catch (InsufficientStockException) {
                        throw ValidationException::withMessages([
                            'stock' => "Stock insuficiente para el producto {$item->product_id}.",
                        ]);
                    }
                }

                $item->update([
                    'stock_movement_id' => $movement?->id,
                    'base_unit_cost' => $item->base_unit_cost === null
                        ? $item->product->last_purchase_cost
                        : $item->base_unit_cost,
                ]);
                if ($movement !== null) {
                    $this->markProductUnitsAsSold($productUnits, $movement->id);
                }
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

            return $sale->refresh()->load(['customer', 'items.product', 'items.variant', 'items.warehouse', 'items.stockMovement']);
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

        return $sale->refresh()->load(['customer', 'items.product.fiscalTaxRate', 'items.variant', 'items.warehouse']);
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

    private function resolveLineDiscount(array $item, array $quote, float $totalAmount, float $baseTotal, float $localTotal): array
    {
        $type = $item['discount_type'] ?? null;
        $value = isset($item['discount_value']) ? (float) $item['discount_value'] : 0.0;
        $reason = $item['discount_reason'] ?? null;

        if ($type === null || $value <= 0) {
            return [
                'type' => null,
                'value' => 0.0,
                'amount' => 0.0,
                'base_amount' => 0.0,
                'local_amount' => 0.0,
                'reason' => null,
            ];
        }

        if (! in_array($type, ['percent', 'fixed'], true)) {
            throw ValidationException::withMessages([
                'discount_type' => 'El tipo de descuento no es valido.',
            ]);
        }

        if ($type === 'percent' && $value > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'El descuento porcentual no puede superar 100%.',
            ]);
        }

        $saleCurrency = strtoupper((string) $quote['sale_currency']);
        $exchangeRate = $quote['exchange_rate'] === null ? null : (float) $quote['exchange_rate'];

        if ($type === 'percent') {
            $discountAmount = round($totalAmount * $value / 100, 4);
            $discountBase = round($baseTotal * $value / 100, 4);
            $discountLocal = round($localTotal * $value / 100, 4);
        } else {
            $discountAmount = round($value, 4);
            if ($discountAmount > $totalAmount) {
                throw ValidationException::withMessages([
                    'discount_value' => 'El descuento no puede ser mayor al total de la linea.',
                ]);
            }

            if ($saleCurrency === Product::CURRENCY_USD) {
                $discountBase = $discountAmount;
                $discountLocal = $exchangeRate === null ? 0.0 : round($discountAmount * $exchangeRate, 4);
            } else {
                if (! $exchangeRate) {
                    throw ValidationException::withMessages([
                        'discount_value' => 'El descuento fijo en bolivares requiere una tasa activa.',
                    ]);
                }

                $discountBase = round($discountAmount / $exchangeRate, 4);
                $discountLocal = $discountAmount;
            }
        }

        if ($discountBase > $baseTotal || $discountAmount > $totalAmount || $discountLocal > $localTotal) {
            throw ValidationException::withMessages([
                'discount_value' => 'El descuento no puede dejar la linea en negativo.',
            ]);
        }

        return [
            'type' => $type,
            'value' => round($value, 4),
            'amount' => $discountAmount,
            'base_amount' => $discountBase,
            'local_amount' => $discountLocal,
            'reason' => $reason,
        ];
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
