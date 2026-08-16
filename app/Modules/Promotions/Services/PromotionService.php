<?php

namespace App\Modules\Promotions\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Promotions\Models\Promotion;
use Illuminate\Validation\ValidationException;

class PromotionService
{
    public function __construct(
        private readonly ProductPriceService $prices,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function applyToItems(array $items, ?int $promotionId = null, ?string $promotionCode = null): array
    {
        if ($promotionId === null && $promotionCode === null) {
            return $items;
        }

        $promotion = Promotion::query()
            ->with('items')
            ->when($promotionId !== null, fn ($query) => $query->whereKey($promotionId))
            ->when($promotionCode !== null && $promotionId === null, fn ($query) => $query->where('code', mb_strtoupper(trim($promotionCode))))
            ->first();

        if (! $promotion || ($promotionCode !== null && mb_strtoupper(trim($promotionCode)) !== $promotion->code)) {
            throw ValidationException::withMessages([
                'promotion_code' => 'La promocion indicada no existe para esta empresa.',
            ]);
        }

        if (! $promotion->is_active || ($promotion->starts_at && $promotion->starts_at->isFuture()) || ($promotion->ends_at && $promotion->ends_at->isPast())) {
            throw ValidationException::withMessages([
                'promotion_id' => 'La promocion ya no esta vigente.',
            ]);
        }

        return match ($promotion->benefit_type) {
            Promotion::BENEFIT_FIXED_BUNDLE_PRICE => $this->applyFixedBundle($items, $promotion),
            Promotion::BENEFIT_PERCENT_DISCOUNT => $this->applyPercentageDiscount($items, $promotion),
            Promotion::BENEFIT_FIXED_DISCOUNT => $this->applyFixedDiscount($items, $promotion),
            Promotion::BENEFIT_FIXED_ITEM_PRICE => $this->applyFixedItemPrice($items, $promotion),
            Promotion::BENEFIT_FREE_ITEM => $this->applyFreeItem($items, $promotion),
            Promotion::BENEFIT_BUY_X_GET_Y => $this->applyBuyXGetY($items, $promotion),
            default => throw ValidationException::withMessages([
                'promotion_id' => 'Este tipo de promocion aun no esta disponible para checkout.',
            ]),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyFixedBundle(array $items, Promotion $promotion): array
    {
        $required = $promotion->items->keyBy('product_id');
        $grouped = collect($items)->groupBy('product_id');

        foreach ($required as $productId => $requiredItem) {
            $actualQuantity = $grouped->get($productId, collect())->sum(fn (array $item): float => (float) $item['quantity']);
            if ($actualQuantity < (float) $requiredItem->quantity) {
                throw ValidationException::withMessages([
                    'promotion_id' => "El carrito no contiene la cantidad requerida del producto {$productId}.",
                ]);
            }
        }

        $quotes = [];
        $normalBaseTotal = 0.0;
        $matchingIndexes = [];

        foreach ($items as $index => $item) {
            if (! $required->has((int) $item['product_id'])) {
                continue;
            }

            $product = Product::query()->findOrFail($item['product_id']);
            $quote = $this->prices->quote(
                $product,
                $item['price_list_id'] ?? null,
                $item['price_source'] ?? null,
            );
            $quantity = (float) $item['quantity'];
            $normalBase = round((float) $quote['base_price_usd'] * $quantity, 4);
            $normalLocal = $quote['price_ves'] === null
                ? 0.0
                : round((float) $quote['price_ves'] * $quantity, 4);

            $quotes[$index] = [
                'quote' => $quote,
                'normal_base' => $normalBase,
                'normal_local' => $normalLocal,
            ];
            $normalBaseTotal += $normalBase;
            $matchingIndexes[] = $index;
        }

        if ($normalBaseTotal <= 0 || $matchingIndexes === []) {
            throw ValidationException::withMessages([
                'promotion_id' => 'La promocion no tiene componentes con precio valido.',
            ]);
        }

        $targetBaseTotal = (float) $promotion->price_usd;
        $result = $items;
        $allocatedBase = 0.0;
        $lastMatchingIndex = $matchingIndexes[array_key_last($matchingIndexes)];

        foreach ($quotes as $index => $data) {
            $baseTotal = $index === $lastMatchingIndex
                ? round($targetBaseTotal - $allocatedBase, 4)
                : round($targetBaseTotal * $data['normal_base'] / $normalBaseTotal, 4);
            $allocatedBase += $baseTotal;
            $quote = $data['quote'];
            $quantity = (float) $items[$index]['quantity'];
            $localTotal = $quote['exchange_rate'] === null
                ? $baseTotal
                : round($baseTotal * (float) $quote['exchange_rate'], 4);
            $saleTotal = $quote['sale_currency'] === Product::CURRENCY_VES ? $localTotal : $baseTotal;

            $result[$index] = array_merge($items[$index], [
                'promotion_id' => $promotion->id,
                'promotion_code' => $promotion->code,
                'promotion_name' => $promotion->name,
                'promotion_benefit_type' => $promotion->benefit_type,
                'promotion_price_usd' => (float) $promotion->price_usd,
                'promotion_discount_percent' => null,
                'promotion_discount_amount_usd' => null,
                'promotion_base_total_amount' => $baseTotal,
                'promotion_total_amount' => $saleTotal,
                'promotion_local_total_amount' => $localTotal,
                'promotion_adjustment_base_amount' => round($baseTotal - $data['normal_base'], 4),
                'promotion_adjustment_local_amount' => round($localTotal - $data['normal_local'], 4),
            ]);

            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyPercentageDiscount(array $items, Promotion $promotion): array
    {
        $required = $promotion->items->keyBy('product_id');
        $grouped = collect($items)->groupBy('product_id');

        foreach ($required as $productId => $requiredItem) {
            $actualQuantity = $grouped->get($productId, collect())->sum(fn (array $item): float => (float) $item['quantity']);
            if ($actualQuantity < (float) $requiredItem->quantity) {
                throw ValidationException::withMessages([
                    'promotion_id' => "El carrito no contiene la cantidad requerida del producto {$productId}.",
                ]);
            }
        }

        $percent = (float) $promotion->discount_percent;
        if ($percent <= 0 || $percent > 100) {
            throw ValidationException::withMessages([
                'promotion_id' => 'La promocion porcentual tiene un porcentaje invalido.',
            ]);
        }

        $result = $items;
        foreach ($items as $index => $item) {
            if (! $required->has((int) $item['product_id'])) {
                continue;
            }

            $product = Product::query()->findOrFail($item['product_id']);
            $quote = $this->prices->quote(
                $product,
                $item['price_list_id'] ?? null,
                $item['price_source'] ?? null,
            );
            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
            }

            $normalBase = round((float) $quote['base_price_usd'] * $quantity, 4);
            $normalLocal = $quote['price_ves'] === null
                ? 0.0
                : round((float) $quote['price_ves'] * $quantity, 4);
            $factor = (100 - $percent) / 100;
            $baseTotal = round($normalBase * $factor, 4);
            $localTotal = $quote['exchange_rate'] === null
                ? $baseTotal
                : round($normalLocal * $factor, 4);
            $saleTotal = $quote['sale_currency'] === Product::CURRENCY_VES ? $localTotal : $baseTotal;

            $result[$index] = array_merge($item, [
                'promotion_id' => $promotion->id,
                'promotion_code' => $promotion->code,
                'promotion_name' => $promotion->name,
                'promotion_benefit_type' => $promotion->benefit_type,
                'promotion_price_usd' => null,
                'promotion_discount_percent' => $percent,
                'promotion_discount_amount_usd' => null,
                'promotion_base_total_amount' => $baseTotal,
                'promotion_total_amount' => $saleTotal,
                'promotion_local_total_amount' => $localTotal,
                'promotion_adjustment_base_amount' => round($baseTotal - $normalBase, 4),
                'promotion_adjustment_local_amount' => round($localTotal - $normalLocal, 4),
            ]);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyFixedDiscount(array $items, Promotion $promotion): array
    {
        $required = $promotion->items->keyBy('product_id');
        $grouped = collect($items)->groupBy('product_id');

        foreach ($required as $productId => $requiredItem) {
            $actualQuantity = $grouped->get($productId, collect())->sum(fn (array $item): float => (float) $item['quantity']);
            if ($actualQuantity < (float) $requiredItem->quantity) {
                throw ValidationException::withMessages([
                    'promotion_id' => "El carrito no contiene la cantidad requerida del producto {$productId}.",
                ]);
            }
        }

        $quotes = [];
        $normalBaseTotal = 0.0;
        $matchingIndexes = [];

        foreach ($items as $index => $item) {
            if (! $required->has((int) $item['product_id'])) {
                continue;
            }

            $product = Product::query()->findOrFail($item['product_id']);
            $quote = $this->prices->quote(
                $product,
                $item['price_list_id'] ?? null,
                $item['price_source'] ?? null,
            );
            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
            }

            $normalBase = round((float) $quote['base_price_usd'] * $quantity, 4);
            $normalLocal = $quote['price_ves'] === null
                ? 0.0
                : round((float) $quote['price_ves'] * $quantity, 4);
            $quotes[$index] = [
                'quote' => $quote,
                'normal_base' => $normalBase,
                'normal_local' => $normalLocal,
            ];
            $normalBaseTotal += $normalBase;
            $matchingIndexes[] = $index;
        }

        if ($normalBaseTotal <= 0 || $matchingIndexes === []) {
            throw ValidationException::withMessages([
                'promotion_id' => 'La promocion no tiene componentes con precio valido.',
            ]);
        }

        $discountAmount = min((float) $promotion->discount_amount_usd, $normalBaseTotal);
        $targetBaseTotal = round($normalBaseTotal - $discountAmount, 4);
        $result = $items;
        $allocatedBase = 0.0;
        $lastMatchingIndex = $matchingIndexes[array_key_last($matchingIndexes)];

        foreach ($quotes as $index => $data) {
            $baseTotal = $index === $lastMatchingIndex
                ? round($targetBaseTotal - $allocatedBase, 4)
                : round($targetBaseTotal * $data['normal_base'] / $normalBaseTotal, 4);
            $allocatedBase += $baseTotal;
            $quote = $data['quote'];
            $localTotal = $quote['exchange_rate'] === null
                ? $baseTotal
                : round($baseTotal * (float) $quote['exchange_rate'], 4);
            $saleTotal = $quote['sale_currency'] === Product::CURRENCY_VES ? $localTotal : $baseTotal;

            $result[$index] = array_merge($items[$index], [
                'promotion_id' => $promotion->id,
                'promotion_code' => $promotion->code,
                'promotion_name' => $promotion->name,
                'promotion_benefit_type' => $promotion->benefit_type,
                'promotion_price_usd' => null,
                'promotion_discount_percent' => null,
                'promotion_discount_amount_usd' => $discountAmount,
                'promotion_base_total_amount' => $baseTotal,
                'promotion_total_amount' => $saleTotal,
                'promotion_local_total_amount' => $localTotal,
                'promotion_adjustment_base_amount' => round($baseTotal - $data['normal_base'], 4),
                'promotion_adjustment_local_amount' => round($localTotal - $data['normal_local'], 4),
            ]);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyFixedItemPrice(array $items, Promotion $promotion): array
    {
        $required = $promotion->items->keyBy('product_id');
        $grouped = collect($items)->groupBy('product_id');

        foreach ($required as $productId => $requiredItem) {
            $actualQuantity = $grouped->get($productId, collect())->sum(fn (array $item): float => (float) $item['quantity']);
            if ($actualQuantity < (float) $requiredItem->quantity) {
                throw ValidationException::withMessages([
                    'promotion_id' => "El carrito no contiene la cantidad requerida del producto {$productId}.",
                ]);
            }
        }

        $unitPrice = (float) $promotion->price_usd;
        if ($unitPrice < 0) {
            throw ValidationException::withMessages([
                'promotion_id' => 'La promocion tiene un precio unitario invalido.',
            ]);
        }

        $result = $items;
        foreach ($items as $index => $item) {
            if (! $required->has((int) $item['product_id'])) {
                continue;
            }

            $product = Product::query()->findOrFail($item['product_id']);
            $quote = $this->prices->quote(
                $product,
                $item['price_list_id'] ?? null,
                $item['price_source'] ?? null,
            );
            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
            }

            $normalBase = round((float) $quote['base_price_usd'] * $quantity, 4);
            $normalLocal = $quote['price_ves'] === null
                ? 0.0
                : round((float) $quote['price_ves'] * $quantity, 4);
            $baseTotal = round($unitPrice * $quantity, 4);
            $localTotal = $quote['exchange_rate'] === null
                ? $baseTotal
                : round($baseTotal * (float) $quote['exchange_rate'], 4);
            $saleTotal = $quote['sale_currency'] === Product::CURRENCY_VES ? $localTotal : $baseTotal;

            $result[$index] = array_merge($item, [
                'promotion_id' => $promotion->id,
                'promotion_code' => $promotion->code,
                'promotion_name' => $promotion->name,
                'promotion_benefit_type' => $promotion->benefit_type,
                'promotion_price_usd' => $unitPrice,
                'promotion_discount_percent' => null,
                'promotion_discount_amount_usd' => null,
                'promotion_base_total_amount' => $baseTotal,
                'promotion_total_amount' => $saleTotal,
                'promotion_local_total_amount' => $localTotal,
                'promotion_adjustment_base_amount' => round($baseTotal - $normalBase, 4),
                'promotion_adjustment_local_amount' => round($localTotal - $normalLocal, 4),
            ]);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyFreeItem(array $items, Promotion $promotion): array
    {
        return $this->applyFixedItemPrice($items, $promotion);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyBuyXGetY(array $items, Promotion $promotion): array
    {
        $triggers = $promotion->items->where('item_role', 'trigger')->keyBy('product_id');
        $rewards = $promotion->items->where('item_role', 'reward')->keyBy('product_id');
        if ($triggers->isEmpty() || $rewards->isEmpty()) {
            throw ValidationException::withMessages([
                'promotion_id' => 'La promocion debe tener componentes trigger y reward.',
            ]);
        }

        $grouped = collect($items)->groupBy('product_id');
        $sets = null;
        foreach ($triggers as $productId => $trigger) {
            $actualQuantity = (float) $grouped->get($productId, collect())->sum(fn (array $item): float => (float) $item['quantity']);
            $availableSets = (int) floor($actualQuantity / (float) $trigger->quantity);
            $sets = $sets === null ? $availableSets : min($sets, $availableSets);
        }

        if ($sets === null || $sets < 1) {
            throw ValidationException::withMessages([
                'promotion_id' => 'El carrito no cumple la cantidad requerida para activar la promocion.',
            ]);
        }

        $freeByProduct = [];
        foreach ($rewards as $productId => $reward) {
            $freeQuantity = (float) $reward->quantity * $sets;
            $actualQuantity = (float) $grouped->get($productId, collect())->sum(fn (array $item): float => (float) $item['quantity']);
            if ($actualQuantity < $freeQuantity) {
                throw ValidationException::withMessages([
                    'promotion_id' => "El carrito no contiene la recompensa requerida del producto {$productId}.",
                ]);
            }
            $freeByProduct[(int) $productId] = $freeQuantity;
        }

        $result = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $isTrigger = $triggers->has($productId);
            $freeQuantity = $freeByProduct[$productId] ?? 0.0;
            if (! $isTrigger && $freeQuantity <= 0) {
                $result[] = $item;

                continue;
            }

            $product = Product::query()->findOrFail($productId);
            $quote = $this->prices->quote(
                $product,
                $item['price_list_id'] ?? null,
                $item['price_source'] ?? null,
            );
            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
            }

            $normalBase = round((float) $quote['base_price_usd'] * $quantity, 4);
            $normalLocal = $quote['price_ves'] === null
                ? 0.0
                : round((float) $quote['price_ves'] * $quantity, 4);
            $metadata = [
                'promotion_id' => $promotion->id,
                'promotion_code' => $promotion->code,
                'promotion_name' => $promotion->name,
                'promotion_benefit_type' => $promotion->benefit_type,
                'promotion_price_usd' => $isTrigger ? null : 0.0,
                'promotion_discount_percent' => null,
                'promotion_discount_amount_usd' => null,
            ];

            if ($freeQuantity <= 0) {
                if ($isTrigger) {
                    $result[] = array_merge($item, $metadata, [
                        'promotion_base_total_amount' => $normalBase,
                        'promotion_total_amount' => $quote['sale_currency'] === Product::CURRENCY_VES ? $normalLocal : $normalBase,
                        'promotion_local_total_amount' => $normalLocal,
                        'promotion_adjustment_base_amount' => 0.0,
                        'promotion_adjustment_local_amount' => 0.0,
                    ]);
                } else {
                    $result[] = $item;
                }

                continue;
            }

            $freeQuantityForLine = min($quantity, $freeQuantity);
            $freeByProduct[$productId] -= $freeQuantityForLine;
            if (isset($item['product_unit_ids']) && $item['product_unit_ids'] !== []) {
                if ($freeQuantityForLine !== floor($freeQuantityForLine)) {
                    throw ValidationException::withMessages([
                        'promotion_id' => 'Las recompensas serializadas requieren cantidades enteras.',
                    ]);
                }
                $unitIds = $item['product_unit_ids'];
                $freeUnitIds = array_slice($unitIds, 0, (int) $freeQuantityForLine);
                $paidUnitIds = array_slice($unitIds, (int) $freeQuantityForLine);
            } else {
                $freeUnitIds = $paidUnitIds = [];
            }

            $freeBase = round((float) $quote['base_price_usd'] * $freeQuantityForLine, 4);
            $freeLocal = $quote['price_ves'] === null
                ? 0.0
                : round((float) $quote['price_ves'] * $freeQuantityForLine, 4);
            $freeItem = array_merge($item, $metadata, [
                'quantity' => $freeQuantityForLine,
                'product_unit_ids' => $freeUnitIds,
                'promotion_price_usd' => 0.0,
                'promotion_base_total_amount' => 0.0,
                'promotion_total_amount' => 0.0,
                'promotion_local_total_amount' => 0.0,
                'promotion_adjustment_base_amount' => round(-$freeBase, 4),
                'promotion_adjustment_local_amount' => round(-$freeLocal, 4),
            ]);
            $result[] = $freeItem;

            $paidQuantity = round($quantity - $freeQuantityForLine, 4);
            if ($paidQuantity > 0) {
                $paidItem = [
                    'quantity' => $paidQuantity,
                    'product_unit_ids' => $paidUnitIds,
                ];
                if ($isTrigger) {
                    $paidBase = round((float) $quote['base_price_usd'] * $paidQuantity, 4);
                    $paidLocal = $quote['price_ves'] === null
                        ? 0.0
                        : round((float) $quote['price_ves'] * $paidQuantity, 4);
                    $paidItem = array_merge($metadata, $paidItem, [
                        'promotion_base_total_amount' => $paidBase,
                        'promotion_total_amount' => $quote['sale_currency'] === Product::CURRENCY_VES ? $paidLocal : $paidBase,
                        'promotion_local_total_amount' => $paidLocal,
                        'promotion_adjustment_base_amount' => 0.0,
                        'promotion_adjustment_local_amount' => 0.0,
                    ]);
                }
                $result[] = array_merge($item, $paidItem);
            }
        }

        return $result;
    }
}
