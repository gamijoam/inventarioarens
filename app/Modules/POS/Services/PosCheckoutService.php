<?php

namespace App\Modules\POS\Services;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\PriceList;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosCheckoutService
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly CashRegisterService $cashRegister,
        private readonly AccountsReceivableService $accountsReceivable,
        private readonly InventoryMovementService $inventory,
    ) {
    }

    public function checkout(
        User $cashier,
        CashRegisterSession $cashRegisterSession,
        array $items,
        array $payments,
        ?int $customerId = null,
        ?string $customerName = null,
    ): PosOrder
    {
        return DB::transaction(function () use ($cashier, $cashRegisterSession, $items, $payments, $customerId, $customerName): PosOrder {
            $cashRegisterSession = CashRegisterSession::query()->lockForUpdate()->findOrFail($cashRegisterSession->id);
            $this->assertCashRegisterCanSell($cashRegisterSession, $cashier);
            $resolvedPaymentMethods = $this->validatePaymentMethods($items, $payments);

            $sale = $this->sales->createDraft($cashier, $items, $customerId);

            $order = PosOrder::create([
                'sale_id' => $sale->id,
                'cash_register_session_id' => $cashRegisterSession->id,
                'customer_id' => $customerId,
                'status' => PosOrder::STATUS_OPEN,
                'cashier_id' => $cashier->id,
                'customer_name' => $customerName,
                'total_base_amount' => $sale->total_base_amount,
                'total_local_amount' => $sale->total_local_amount,
                'opened_at' => now(),
            ]);

            $paidBase = 0.0;
            $paidLocal = 0.0;

            foreach ($payments as $index => $payment) {
                $resolved = $this->resolvePayment($payment);
                $status = $payment['status'] ?? PosPayment::STATUS_CAPTURED;
                $paymentMethod = $resolvedPaymentMethods[$index] ?? null;

                $posPayment = PosPayment::create([
                    'pos_order_id' => $order->id,
                    'payment_method_id' => $paymentMethod?->id,
                    'method' => $payment['method'],
                    'currency' => strtoupper($payment['currency']),
                    'amount' => $payment['amount'],
                    'amount_base' => $resolved['amount_base'],
                    'amount_local' => $resolved['amount_local'],
                    'exchange_rate_type_id' => $resolved['exchange_rate_type_id'],
                    'exchange_rate_type_code' => $resolved['exchange_rate_type_code'],
                    'exchange_rate' => $resolved['exchange_rate'],
                    'status' => $status,
                    'reference' => $payment['reference'] ?? null,
                    'external_provider' => $payment['external_provider'] ?? null,
                    'metadata' => $payment['metadata'] ?? null,
                ]);

                if ($status === PosPayment::STATUS_CAPTURED) {
                    $paidBase += $resolved['amount_base'];
                    $paidLocal += $resolved['amount_local'] ?? 0.0;
                    $this->cashRegister->recordPosPayment($cashRegisterSession, $posPayment, $cashier);
                }
            }

            $order->update([
                'paid_base_amount' => round($paidBase, 4),
                'paid_local_amount' => round($paidLocal, 4),
            ]);

            if ($this->coversTotal($paidBase, (float) $sale->total_base_amount)) {
                $sale = $this->sales->confirm($sale, $cashier);
                $this->syncCapturedPaymentsToReceivable($order->refresh(), $cashier);
                $order->update([
                    'status' => PosOrder::STATUS_PAID,
                    'paid_at' => now(),
                    'closed_at' => now(),
                ]);
            } else {
                $this->reserveOrderInventory($order, $cashier);
            }

            return $order->refresh()->load(['cashRegisterSession', 'customer', 'sale.customer', 'sale.items.product', 'sale.items.warehouse', 'payments']);
        });
    }

    public function addPayments(PosOrder $order, User $cashier, array $payments): PosOrder
    {
        return DB::transaction(function () use ($order, $cashier, $payments): PosOrder {
            $order = PosOrder::query()
                ->with(['sale.items', 'cashRegisterSession', 'payments'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($order->status !== PosOrder::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden completar cobros de ordenes POS pendientes.',
                ]);
            }

            if (! $order->sale || $order->sale->status !== Sale::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'order' => 'La venta asociada ya no esta disponible para completar cobro.',
                ]);
            }

            $cashRegisterSession = CashRegisterSession::query()->lockForUpdate()->findOrFail($order->cash_register_session_id);
            $this->assertCashRegisterCanSell($cashRegisterSession, $cashier);

            $resolvedPaymentMethods = $this->validatePaymentMethods($this->itemsForExistingOrder($order), $payments);

            foreach ($payments as $index => $payment) {
                $resolved = $this->resolvePayment($payment);
                $status = $payment['status'] ?? PosPayment::STATUS_CAPTURED;
                $paymentMethod = $resolvedPaymentMethods[$index] ?? null;

                $posPayment = PosPayment::create([
                    'pos_order_id' => $order->id,
                    'payment_method_id' => $paymentMethod?->id,
                    'method' => $payment['method'],
                    'currency' => strtoupper($payment['currency']),
                    'amount' => $payment['amount'],
                    'amount_base' => $resolved['amount_base'],
                    'amount_local' => $resolved['amount_local'],
                    'exchange_rate_type_id' => $resolved['exchange_rate_type_id'],
                    'exchange_rate_type_code' => $resolved['exchange_rate_type_code'],
                    'exchange_rate' => $resolved['exchange_rate'],
                    'status' => $status,
                    'reference' => $payment['reference'] ?? null,
                    'external_provider' => $payment['external_provider'] ?? null,
                    'metadata' => $payment['metadata'] ?? null,
                ]);

                if ($status === PosPayment::STATUS_CAPTURED) {
                    $this->cashRegister->recordPosPayment($cashRegisterSession, $posPayment, $cashier);
                }
            }

            $order->refresh()->load('payments');
            $paidBase = (float) $order->payments
                ->where('status', PosPayment::STATUS_CAPTURED)
                ->sum('amount_base');
            $paidLocal = (float) $order->payments
                ->where('status', PosPayment::STATUS_CAPTURED)
                ->sum(fn (PosPayment $payment) => (float) ($payment->amount_local ?? 0));

            $order->update([
                'paid_base_amount' => round($paidBase, 4),
                'paid_local_amount' => round($paidLocal, 4),
            ]);

            if ($this->coversTotal($paidBase, (float) $order->total_base_amount)) {
                $this->releaseOrderReservation($order, $cashier);
                $sale = $this->sales->confirm($order->sale, $cashier);
                $this->syncCapturedPaymentsToReceivable($order->refresh(), $cashier);
                $order->update([
                    'status' => PosOrder::STATUS_PAID,
                    'paid_at' => now(),
                    'closed_at' => now(),
                ]);
                $order->setRelation('sale', $sale);
            }

            return $order->refresh()->load(['cashRegisterSession', 'customer', 'sale.customer', 'sale.items.product', 'sale.items.warehouse', 'payments']);
        });
    }

    private function assertCashRegisterCanSell(CashRegisterSession $session, User $cashier): void
    {
        if ($session->status !== CashRegisterSession::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'cash_register_session_id' => 'La caja seleccionada no esta abierta.',
            ]);
        }

        if ((int) $session->cashier_id !== (int) $cashier->id) {
            throw ValidationException::withMessages([
                'cash_register_session_id' => 'La caja seleccionada pertenece a otro cajero.',
            ]);
        }
    }

    private function validatePaymentMethods(array $items, array $payments): array
    {
        $priceLists = $this->priceListsForItems($items);
        $restrictedPriceLists = $priceLists->filter(fn (PriceList $priceList): bool => $priceList->paymentMethods->isNotEmpty());
        $resolved = [];

        foreach ($payments as $index => $payment) {
            $paymentMethod = $this->resolveConfiguredPaymentMethod($payment, $restrictedPriceLists, $index);
            $resolved[$index] = $paymentMethod;

            if (! $paymentMethod) {
                if ($restrictedPriceLists->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        "payments.{$index}.payment_method_id" => 'El pago no coincide con un método activo permitido para la lista de precio.',
                    ]);
                }

                continue;
            }

            if ($paymentMethod->method !== $payment['method']) {
                throw ValidationException::withMessages([
                    "payments.{$index}.method" => 'El método de pago no coincide con el método configurado.',
                ]);
            }

            if (! $paymentMethod->allowsCurrency(strtoupper($payment['currency']))) {
                throw ValidationException::withMessages([
                    "payments.{$index}.currency" => 'La moneda del pago no está permitida para este método.',
                ]);
            }

            if ($paymentMethod->requires_reference && empty($payment['reference'])) {
                throw ValidationException::withMessages([
                    "payments.{$index}.reference" => 'Este método de pago requiere referencia.',
                ]);
            }

            foreach ($restrictedPriceLists as $priceList) {
                if (! $priceList->paymentMethods->contains('id', $paymentMethod->id)) {
                    throw ValidationException::withMessages([
                        "payments.{$index}.payment_method_id" => "El método de pago no está permitido para la lista de precio {$priceList->name}.",
                    ]);
                }
            }
        }

        return $resolved;
    }

    private function priceListsForItems(array $items)
    {
        $defaultPriceList = null;
        $priceListIds = collect($items)
            ->map(function (array $item) use (&$defaultPriceList): ?int {
                if (! empty($item['price_list_id'])) {
                    return (int) $item['price_list_id'];
                }

                $defaultPriceList ??= PriceList::query()
                    ->where('is_default', true)
                    ->where('is_active', true)
                    ->first();

                return $defaultPriceList?->id;
            })
            ->filter()
            ->unique()
            ->values();

        if ($priceListIds->isEmpty()) {
            return collect();
        }

        return PriceList::query()
            ->with('paymentMethods')
            ->whereIn('id', $priceListIds)
            ->get();
    }

    private function itemsForExistingOrder(PosOrder $order): array
    {
        $order->loadMissing('sale.items');

        return $order->sale->items
            ->map(fn ($item): array => [
                'price_list_id' => $item->price_list_id,
            ])
            ->all();
    }

    private function resolveConfiguredPaymentMethod(array $payment, $restrictedPriceLists, int $index): ?PaymentMethod
    {
        $query = PaymentMethod::query()
            ->where('is_active', true);

        if (! empty($payment['payment_method_id'])) {
            $paymentMethod = (clone $query)->find($payment['payment_method_id']);
            if (! $paymentMethod) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payment_method_id" => 'El método de pago seleccionado no está activo o no pertenece a la empresa actual.',
                ]);
            }

            return $paymentMethod;
        }

        $currency = strtoupper($payment['currency']);
        $allowedIds = $restrictedPriceLists
            ->flatMap(fn (PriceList $priceList) => $priceList->paymentMethods->pluck('id'))
            ->unique()
            ->values();

        return $query
            ->where('method', $payment['method'])
            ->where(function ($query) use ($currency): void {
                $query
                    ->where('currency_mode', PaymentMethod::CURRENCY_FLEXIBLE)
                    ->orWhere('currency_mode', $currency);
            })
            ->when($allowedIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $allowedIds))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    private function resolvePayment(array $payment): array
    {
        $currency = strtoupper($payment['currency']);
        $amount = (float) $payment['amount'];
        $rateType = null;
        $rate = null;

        if ($currency === Product::CURRENCY_VES || isset($payment['exchange_rate_type_id'])) {
            $rateType = $this->rateTypeFor($payment['exchange_rate_type_id'] ?? null);
            $rate = $this->activeRateFor($rateType);
        }

        $exchangeRate = $rate ? (float) $rate->rate : null;

        if ($currency === Product::CURRENCY_VES && ! $exchangeRate) {
            throw ValidationException::withMessages([
                'payments' => 'El pago en bolivares requiere una tasa activa.',
            ]);
        }

        return [
            'amount_base' => $currency === Product::CURRENCY_USD ? round($amount, 4) : round($amount / $exchangeRate, 4),
            'amount_local' => $currency === Product::CURRENCY_VES ? round($amount, 4) : ($exchangeRate ? round($amount * $exchangeRate, 4) : null),
            'exchange_rate_type_id' => $rateType?->id,
            'exchange_rate_type_code' => $rateType?->code,
            'exchange_rate' => $exchangeRate,
        ];
    }

    private function rateTypeFor(?int $rateTypeId): ExchangeRateType
    {
        if ($rateTypeId) {
            return ExchangeRateType::query()->findOrFail($rateTypeId);
        }

        $rateType = ExchangeRateType::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if (! $rateType) {
            throw ValidationException::withMessages([
                'payments' => 'No existe un tipo de tasa activo por defecto para el pago.',
            ]);
        }

        return $rateType;
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

    private function coversTotal(float $paidBase, float $totalBase): bool
    {
        return round($paidBase, 4) + 0.0001 >= round($totalBase, 4);
    }

    private function reserveOrderInventory(PosOrder $order, User $cashier): void
    {
        if ($this->orderHasReservation($order)) {
            return;
        }

        $order->loadMissing(['sale.items.product', 'sale.items.warehouse']);

        foreach ($order->sale->items as $item) {
            $movement = $this->inventory->reserve(
                warehouse: $item->warehouse,
                product: $item->product,
                quantity: (float) $item->quantity,
                createdBy: $cashier,
                reason: "Reserva POS #{$order->id}",
                referenceType: PosOrder::class,
                referenceId: $order->id,
            );

            $this->reserveProductUnitsForSaleItem($item, $movement);
        }
    }

    private function releaseOrderReservation(PosOrder $order, User $cashier): void
    {
        if (! $this->orderHasReservation($order)) {
            return;
        }

        $order->loadMissing(['sale.items.product', 'sale.items.warehouse']);

        foreach ($order->sale->items as $item) {
            $this->inventory->release(
                warehouse: $item->warehouse,
                product: $item->product,
                quantity: (float) $item->quantity,
                createdBy: $cashier,
                reason: "Liberacion reserva POS #{$order->id}",
                referenceType: PosOrder::class,
                referenceId: $order->id,
            );

            $this->releaseReservedUnitsForSaleItem($item);
        }
    }

    private function orderHasReservation(PosOrder $order): bool
    {
        return StockMovement::query()
            ->where('type', 'reserved')
            ->where('reference_type', PosOrder::class)
            ->where('reference_id', $order->id)
            ->exists();
    }

    private function reserveProductUnitsForSaleItem(SaleItem $item, StockMovement $movement): void
    {
        $unitIds = $item->product_unit_ids ?? [];

        if ($unitIds === []) {
            return;
        }

        $units = ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($units->count() !== count($unitIds)) {
            throw ValidationException::withMessages([
                'items' => 'Uno o mas seriales/IMEI ya no estan disponibles para reservar.',
            ]);
        }

        foreach ($unitIds as $unitId) {
            $unit = $units->get($unitId);

            if ((int) $unit->product_id !== (int) $item->product_id || (int) $unit->warehouse_id !== (int) $item->warehouse_id) {
                throw ValidationException::withMessages([
                    'items' => 'Uno o mas seriales/IMEI no pertenecen al producto o almacen seleccionado.',
                ]);
            }

            if ($unit->status !== ProductUnit::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    'items' => 'Uno o mas seriales/IMEI ya no estan disponibles para reservar.',
                ]);
            }

            $unit->update([
                'status' => ProductUnit::STATUS_RESERVED,
                'released_stock_movement_id' => $movement->id,
            ]);
        }
    }

    private function releaseReservedUnitsForSaleItem(SaleItem $item): void
    {
        $unitIds = $item->product_unit_ids ?? [];

        if ($unitIds === []) {
            return;
        }

        $units = ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get();

        foreach ($units as $unit) {
            if ($unit->status !== ProductUnit::STATUS_RESERVED) {
                continue;
            }

            $unit->update([
                'status' => ProductUnit::STATUS_AVAILABLE,
                'released_stock_movement_id' => null,
            ]);
        }
    }

    private function syncCapturedPaymentsToReceivable(PosOrder $order, User $cashier): void
    {
        $account = AccountsReceivable::query()
            ->where('sale_id', $order->sale_id)
            ->lockForUpdate()
            ->first();

        if (! $account) {
            return;
        }

        $order->load('payments');

        foreach ($order->payments as $payment) {
            if ($payment->status !== PosPayment::STATUS_CAPTURED) {
                continue;
            }

            $this->accountsReceivable->registerPosPayment($account, $cashier, $payment);
            $account->refresh();
        }
    }
}
