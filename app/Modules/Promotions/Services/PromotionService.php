<?php

namespace App\Modules\Promotions\Services;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductPriceService;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Models\SalePromotionApplication;
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
    public function applyToItems(
        array $items,
        ?int $promotionId = null,
        ?string $promotionCode = null,
        ?array $payments = null,
    ): array {
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

        if ($payments !== null) {
            $this->assertPaymentCurrencyAllowed($promotion, $payments);
        }

        $result = match ($promotion->benefit_type) {
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

        return $this->applyFiscalTaxTreatment($result, $promotion);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{promotion_id:int, instance_uuid:string, sets:int}>  $comboApplications
     * @param  list<array{promotion_id:int, item_index:int}>  $productOfferApplications
     * @param  list<array<string, mixed>>|null  $payments
     * @return list<array<string, mixed>>
     */
    public function applyOrderPromotions(
        array $items,
        User $actor,
        ?int $invoicePromotionId = null,
        ?string $invoicePromotionCode = null,
        array $comboApplications = [],
        ?array $payments = null,
        bool $invoiceRequested = false,
        array $productOfferApplications = [],
    ): array {
        $result = array_values($items);

        $selectedOfferIndexes = [];
        foreach ($productOfferApplications as $productOfferApplication) {
            $itemIndex = (int) ($productOfferApplication['item_index'] ?? -1);
            if (! array_key_exists($itemIndex, $result)) {
                throw ValidationException::withMessages([
                    'product_offer_applications' => 'El item_index debe corresponder a una linea de la orden.',
                ]);
            }
            if (in_array($itemIndex, $selectedOfferIndexes, true)) {
                throw ValidationException::withMessages([
                    'product_offer_applications' => 'Una linea solo puede tener una oferta de producto seleccionada.',
                ]);
            }
            $selectedOfferIndexes[] = $itemIndex;

            if (($result[$itemIndex]['combo_instance_uuid'] ?? null) !== null) {
                throw ValidationException::withMessages([
                    'product_offer_applications' => 'Las ofertas de producto no se pueden aplicar a lineas de combos.',
                ]);
            }

            $promotion = $this->resolvePromotion((int) $productOfferApplication['promotion_id'], null);
            if ($promotion->scope !== Promotion::SCOPE_PRODUCT_OFFER) {
                throw ValidationException::withMessages([
                    'product_offer_applications' => 'La seleccion indicada no corresponde a una oferta de producto.',
                ]);
            }
            if (! in_array($promotion->benefit_type, [Promotion::BENEFIT_FIXED_ITEM_PRICE, Promotion::BENEFIT_FREE_ITEM], true)) {
                throw ValidationException::withMessages([
                    'product_offer_applications' => 'La oferta de producto debe ser de precio fijo o articulo gratis.',
                ]);
            }

            $item = $result[$itemIndex];
            $requiredItem = $promotion->items->firstWhere('product_id', (int) $item['product_id']);
            if (! $requiredItem || (float) $item['quantity'] < (float) $requiredItem->quantity) {
                throw ValidationException::withMessages([
                    'product_offer_applications' => 'La oferta de producto no corresponde a la linea seleccionada.',
                ]);
            }

            $before = $item;
            $after = $promotion->benefit_type === Promotion::BENEFIT_FREE_ITEM
                ? $this->applyFreeItem([$item], $promotion)[0]
                : $this->applyFixedItemPrice([$item], $promotion)[0];
            $result[$itemIndex] = $this->appendAllocation(
                $after,
                $before,
                $after,
                $promotion,
                'product_offer:'.$itemIndex,
                Promotion::SCOPE_PRODUCT_OFFER,
                SalePromotionApplication::STATUS_VALIDATED,
                $actor,
                $actor,
            );
        }

        foreach ($comboApplications as $comboApplication) {
            $promotion = $this->resolvePromotion((int) $comboApplication['promotion_id'], null);
            if ($promotion->scope !== Promotion::SCOPE_COMBO) {
                throw ValidationException::withMessages([
                    'combo_applications' => 'La seleccion indicada no corresponde a un combo.',
                ]);
            }

            $instanceUuid = trim((string) $comboApplication['instance_uuid']);
            $sets = max(1, (int) $comboApplication['sets']);
            $indexes = collect($result)
                ->filter(fn (array $item): bool => ($item['combo_instance_uuid'] ?? null) === $instanceUuid)
                ->keys()
                ->values()
                ->all();
            if ($indexes === []) {
                throw ValidationException::withMessages([
                    'combo_applications' => "El combo {$promotion->name} no tiene lineas asociadas.",
                ]);
            }

            $subset = collect($indexes)->map(fn (int $index): array => $result[$index])->all();
            $this->assertExactComboComponents($subset, $promotion, $sets);
            $before = $subset;
            $after = match ($promotion->benefit_type) {
                Promotion::BENEFIT_FIXED_BUNDLE_PRICE => $this->applyFixedBundle($subset, $promotion, $sets),
                Promotion::BENEFIT_BUY_X_GET_Y => $this->applyBuyXGetY($subset, $promotion),
                default => throw ValidationException::withMessages([
                    'combo_applications' => 'El tipo de combo indicado no esta soportado.',
                ]),
            };
            $slot = 'combo:'.$instanceUuid;

            foreach ($indexes as $position => $index) {
                $allocated = $this->applyFiscalTaxTreatment($after[$position], $promotion);
                $result[$index] = $this->appendAllocation(
                    $allocated,
                    $before[$position],
                    $allocated,
                    $promotion,
                    $slot,
                    Promotion::SCOPE_COMBO,
                    SalePromotionApplication::STATUS_VALIDATED,
                    $actor,
                    $actor,
                    $instanceUuid,
                );
            }
        }

        if ($invoicePromotionId === null && $invoicePromotionCode === null) {
            return $result;
        }

        $invoicePromotion = $this->resolvePromotion($invoicePromotionId, $invoicePromotionCode);
        if ($invoicePromotion->scope !== Promotion::SCOPE_INVOICE) {
            throw ValidationException::withMessages([
                'invoice_promotion_id' => 'La seleccion indicada no corresponde a una promocion de factura.',
            ]);
        }
        if ($comboApplications !== [] && ! $invoicePromotion->allows_combos) {
            throw ValidationException::withMessages([
                'invoice_promotion_id' => 'Esta promocion de factura no admite combinarse con combos.',
            ]);
        }
        if ($payments !== null) {
            $this->assertPaymentCurrencyAllowed($invoicePromotion, $payments);
        }

        $before = $result;
        $after = match ($invoicePromotion->benefit_type) {
            Promotion::BENEFIT_PERCENT_DISCOUNT => $this->applyPercentageDiscount($result, $invoicePromotion, true),
            Promotion::BENEFIT_FIXED_DISCOUNT => $this->applyFixedDiscount($result, $invoicePromotion, true),
            default => throw ValidationException::withMessages([
                'invoice_promotion_id' => 'El beneficio indicado no es un descuento de factura.',
            ]),
        };
        $status = $invoiceRequested
            ? SalePromotionApplication::STATUS_REQUESTED
            : SalePromotionApplication::STATUS_VALIDATED;

        foreach ($after as $index => $item) {
            $after[$index] = $this->appendAllocation(
                $item,
                $before[$index],
                $item,
                $invoicePromotion,
                'invoice',
                Promotion::SCOPE_INVOICE,
                $status,
                $actor,
                $invoiceRequested ? null : $actor,
            );
        }

        return $after;
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $items
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function applyFiscalTaxTreatment(array $items, Promotion $promotion): array
    {
        if ($promotion->scope !== Promotion::SCOPE_COMBO || $promotion->fiscal_tax_mode !== Promotion::FISCAL_TAX_MODE_OVERRIDE) {
            return $items;
        }

        if (! $promotion->fiscal_tax_rate_id) {
            throw ValidationException::withMessages([
                'promotion_id' => 'El combo requiere una alicuota fiscal para aplicar su override.',
            ]);
        }

        if (array_is_list($items)) {
            return array_map(
                fn (array $item): array => $this->applyFiscalTaxTreatment($item, $promotion),
                $items,
            );
        }

        $items['_fiscal_tax_rate_id'] = (int) $promotion->fiscal_tax_rate_id;

        return $items;
    }

    /**
     * @param  list<int>  $promotionIds
     * @param  list<array<string, mixed>>  $payments
     */
    public function assertPaymentCurrencyAllowedForPromotions(array $promotionIds, array $payments): void
    {
        if ($promotionIds === []) {
            return;
        }

        Promotion::query()
            ->whereIn('id', $promotionIds)
            ->get()
            ->each(fn (Promotion $promotion): bool => $this->assertPaymentCurrencyAllowed($promotion, $payments));
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    public function assertPaymentCurrencyAllowedForApplication(SalePromotionApplication $application, array $payments): void
    {
        if ($application->payment_currency !== Promotion::PAYMENT_CURRENCY_VES) {
            return;
        }

        $this->assertVesOnlyPayments($payments);
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    private function assertPaymentCurrencyAllowed(Promotion $promotion, array $payments): bool
    {
        if ($promotion->payment_currency !== Promotion::PAYMENT_CURRENCY_VES) {
            return true;
        }

        $this->assertVesOnlyPayments($payments);

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    private function assertVesOnlyPayments(array $payments): void
    {

        $activePayments = collect($payments)
            ->reject(fn (array $payment): bool => ($payment['status'] ?? 'captured') === 'failed');

        $hasCreditPayment = $activePayments->contains(fn (array $payment): bool => in_array(
            $payment['method'] ?? null,
            ['customer_credit', 'external_financing'],
            true,
        ));
        if ($activePayments->isEmpty() || $hasCreditPayment || $activePayments->contains(fn (array $payment): bool => strtoupper((string) ($payment['currency'] ?? '')) !== Product::CURRENCY_VES)) {
            throw ValidationException::withMessages([
                'payments' => 'Esta promocion exige pago completo en bolivares (VES), sin credito ni pagos mixtos.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyFixedBundle(array $items, Promotion $promotion, int $sets = 1): array
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

        $targetBaseTotal = (float) $promotion->price_usd * $sets;
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
    private function applyPercentageDiscount(array $items, Promotion $promotion, bool $useCurrentTotals = false): array
    {
        $eligibleIndexes = $this->eligibleItemIndexes($items, $promotion);
        $eligible = array_fill_keys($eligibleIndexes, true);

        $percent = (float) $promotion->discount_percent;
        if ($percent <= 0 || $percent > 100) {
            throw ValidationException::withMessages([
                'promotion_id' => 'La promocion porcentual tiene un porcentaje invalido.',
            ]);
        }

        $result = $items;
        foreach ($items as $index => $item) {
            if (! isset($eligible[$index])) {
                continue;
            }

            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
            }

            [$quote, $normalBase, $normalLocal, $normalSale] = $this->currentItemAmounts($item, $useCurrentTotals);
            $factor = (100 - $percent) / 100;
            $baseTotal = round($normalBase * $factor, 4);
            $localTotal = round($normalLocal * $factor, 4);
            $saleTotal = round($normalSale * $factor, 4);
            $existingAdjustmentBase = (float) ($item['promotion_adjustment_base_amount'] ?? 0);
            $existingAdjustmentLocal = (float) ($item['promotion_adjustment_local_amount'] ?? 0);
            $hasPromotionMetadata = isset($item['promotion_id']);

            $result[$index] = array_merge($item, [
                'promotion_id' => $hasPromotionMetadata ? $item['promotion_id'] : $promotion->id,
                'promotion_code' => $hasPromotionMetadata ? ($item['promotion_code'] ?? null) : $promotion->code,
                'promotion_name' => $hasPromotionMetadata ? ($item['promotion_name'] ?? null) : $promotion->name,
                'promotion_benefit_type' => $hasPromotionMetadata ? ($item['promotion_benefit_type'] ?? null) : $promotion->benefit_type,
                'promotion_price_usd' => $hasPromotionMetadata ? ($item['promotion_price_usd'] ?? null) : null,
                'promotion_discount_percent' => $hasPromotionMetadata ? ($item['promotion_discount_percent'] ?? null) : $percent,
                'promotion_discount_amount_usd' => $hasPromotionMetadata ? ($item['promotion_discount_amount_usd'] ?? null) : null,
                'promotion_base_total_amount' => $baseTotal,
                'promotion_total_amount' => $saleTotal,
                'promotion_local_total_amount' => $localTotal,
                'promotion_adjustment_base_amount' => round($existingAdjustmentBase + $baseTotal - $normalBase, 4),
                'promotion_adjustment_local_amount' => round($existingAdjustmentLocal + $localTotal - $normalLocal, 4),
            ]);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyFixedDiscount(array $items, Promotion $promotion, bool $useCurrentTotals = false): array
    {
        $eligibleIndexes = $this->eligibleItemIndexes($items, $promotion);
        $eligible = array_fill_keys($eligibleIndexes, true);

        $quotes = [];
        $normalBaseTotal = 0.0;
        $matchingIndexes = [];

        foreach ($items as $index => $item) {
            if (! isset($eligible[$index])) {
                continue;
            }

            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
            }

            [$quote, $normalBase, $normalLocal, $normalSale] = $this->currentItemAmounts($item, $useCurrentTotals);
            $quotes[$index] = [
                'quote' => $quote,
                'normal_base' => $normalBase,
                'normal_local' => $normalLocal,
                'normal_sale' => $normalSale,
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
            $factor = $data['normal_base'] <= 0 ? 0 : $baseTotal / $data['normal_base'];
            $localTotal = round($data['normal_local'] * $factor, 4);
            $saleTotal = round($data['normal_sale'] * $factor, 4);
            $existingAdjustmentBase = (float) ($items[$index]['promotion_adjustment_base_amount'] ?? 0);
            $existingAdjustmentLocal = (float) ($items[$index]['promotion_adjustment_local_amount'] ?? 0);
            $hasPromotionMetadata = isset($items[$index]['promotion_id']);

            $result[$index] = array_merge($items[$index], [
                'promotion_id' => $hasPromotionMetadata ? $items[$index]['promotion_id'] : $promotion->id,
                'promotion_code' => $hasPromotionMetadata ? ($items[$index]['promotion_code'] ?? null) : $promotion->code,
                'promotion_name' => $hasPromotionMetadata ? ($items[$index]['promotion_name'] ?? null) : $promotion->name,
                'promotion_benefit_type' => $hasPromotionMetadata ? ($items[$index]['promotion_benefit_type'] ?? null) : $promotion->benefit_type,
                'promotion_price_usd' => $hasPromotionMetadata ? ($items[$index]['promotion_price_usd'] ?? null) : null,
                'promotion_discount_percent' => $hasPromotionMetadata ? ($items[$index]['promotion_discount_percent'] ?? null) : null,
                'promotion_discount_amount_usd' => $hasPromotionMetadata ? ($items[$index]['promotion_discount_amount_usd'] ?? null) : $discountAmount,
                'promotion_base_total_amount' => $baseTotal,
                'promotion_total_amount' => $saleTotal,
                'promotion_local_total_amount' => $localTotal,
                'promotion_adjustment_base_amount' => round($existingAdjustmentBase + $baseTotal - $data['normal_base'], 4),
                'promotion_adjustment_local_amount' => round($existingAdjustmentLocal + $localTotal - $data['normal_local'], 4),
            ]);
        }

        return $result;
    }

    /**
     * Discounts without components apply to every line in the invoice.
     * Existing discounts with components remain product-scoped.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    private function eligibleItemIndexes(array $items, Promotion $promotion): array
    {
        if ($promotion->items->isEmpty()) {
            return array_keys($items);
        }

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

        return collect($items)
            ->filter(fn (array $item): bool => $required->has((int) $item['product_id']))
            ->keys()
            ->all();
    }

    private function resolvePromotion(?int $promotionId, ?string $promotionCode): Promotion
    {
        $promotion = Promotion::query()
            ->with('items')
            ->when($promotionId !== null, fn ($query) => $query->whereKey($promotionId))
            ->when($promotionCode !== null && $promotionId === null, fn ($query) => $query->where('code', mb_strtoupper(trim($promotionCode))))
            ->first();

        if (! $promotion || ($promotionCode !== null && mb_strtoupper(trim($promotionCode)) !== $promotion->code)) {
            throw ValidationException::withMessages([
                'invoice_promotion_id' => 'La promocion indicada no existe para esta empresa.',
            ]);
        }
        if (! $promotion->is_active || ($promotion->starts_at && $promotion->starts_at->isFuture()) || ($promotion->ends_at && $promotion->ends_at->isPast())) {
            throw ValidationException::withMessages([
                'invoice_promotion_id' => 'La promocion ya no esta vigente.',
            ]);
        }

        return $promotion;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function assertExactComboComponents(array $items, Promotion $promotion, int $sets): void
    {
        $expected = $promotion->items
            ->groupBy('product_id')
            ->map(fn ($components): float => (float) $components->sum(fn ($item): float => (float) $item->quantity) * $sets);
        $actual = collect($items)
            ->groupBy('product_id')
            ->map(fn ($lines): float => (float) $lines->sum(fn (array $item): float => (float) $item['quantity']));

        if ($expected->count() !== $actual->count() || $expected->contains(fn (float $quantity, int $productId): bool => abs($quantity - (float) $actual->get($productId, 0)) > 0.0001)) {
            throw ValidationException::withMessages([
                'combo_applications' => "Las cantidades no corresponden al combo {$promotion->name}.",
            ]);
        }
    }

    /**
     * @return array{0:array<string,mixed>,1:float,2:float,3:float}
     */
    private function currentItemAmounts(array $item, bool $useCurrentTotals): array
    {
        $product = Product::query()->findOrFail($item['product_id']);
        $quote = $this->prices->quote(
            $product,
            $item['price_list_id'] ?? null,
            $item['price_source'] ?? null,
        );
        $quantity = (float) $item['quantity'];
        if ($useCurrentTotals && array_key_exists('promotion_base_total_amount', $item)) {
            return [
                $quote,
                (float) $item['promotion_base_total_amount'],
                (float) $item['promotion_local_total_amount'],
                (float) $item['promotion_total_amount'],
            ];
        }

        $base = round((float) $quote['base_price_usd'] * $quantity, 4);
        $local = $quote['price_ves'] === null ? 0.0 : round((float) $quote['price_ves'] * $quantity, 4);
        $sale = round((float) $quote['sale_price'] * $quantity, 4);

        return [$quote, $base, $local, $sale];
    }

    private function appendAllocation(
        array $item,
        array $before,
        array $after,
        Promotion $promotion,
        string $slot,
        string $scope,
        string $status,
        User $requester,
        ?User $validator,
        ?string $instanceUuid = null,
    ): array {
        [, $baseBefore, $localBefore] = $this->currentItemAmounts($before, true);
        [, $baseAfter, $localAfter] = $this->currentItemAmounts($after, true);
        $allocations = $item['_promotion_allocations'] ?? [];
        $allocations[] = [
            'slot' => $slot,
            'scope' => $scope,
            'status' => $status,
            'instance_uuid' => $instanceUuid,
            'requested_by' => $requester->id,
            'validated_by' => $validator?->id,
            'promotion_id' => $promotion->id,
            'promotion_code' => $promotion->code,
            'promotion_name' => $promotion->name,
            'benefit_type' => $promotion->benefit_type,
            'payment_currency' => $promotion->payment_currency,
            'price_usd' => $promotion->price_usd,
            'discount_percent' => $promotion->discount_percent,
            'discount_amount_usd' => $promotion->discount_amount_usd,
            'conditions_snapshot' => [
                'payment_currency' => $promotion->payment_currency,
                'allows_combos' => $promotion->allows_combos,
            ],
            'quantity' => (float) $item['quantity'],
            'base_before_amount' => $baseBefore,
            'local_before_amount' => $localBefore,
            'base_adjustment_amount' => round($baseAfter - $baseBefore, 4),
            'local_adjustment_amount' => round($localAfter - $localBefore, 4),
            'base_after_amount' => $baseAfter,
            'local_after_amount' => $localAfter,
        ];
        $item['_promotion_allocations'] = $allocations;

        return $item;
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
