<?php

namespace App\Modules\SalesReturns\Services;

use App\Models\User;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\FinancialAdjustments\Services\FinancialAdjustmentService;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Models\SalesReturnItem;
use App\Modules\Sync\Services\SyncOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    private const RESERVED_RETURN_STATUSES = [
        SalesReturn::STATUS_REQUESTED,
        SalesReturn::STATUS_APPROVED,
        SalesReturn::STATUS_PROCESSED,
    ];

    public function __construct(
        private readonly InventoryMovementService $inventory,
        private readonly CashRegisterService $cashRegister,
        private readonly FinancialAdjustmentService $financialAdjustments,
        private readonly CustomerCreditService $customerCredits,
        private readonly PosCheckoutService $posCheckout,
        private readonly SyncOutboxService $syncOutbox,
    ) {}

    public function create(User $user, array $data): SalesReturn
    {
        return DB::transaction(function () use ($user, $data): SalesReturn {
            $sale = Sale::query()->with('items.product', 'items.warehouse')->lockForUpdate()->findOrFail($data['sale_id']);

            if ($sale->status !== Sale::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'sale_id' => 'Solo se pueden devolver ventas confirmadas.',
                ]);
            }

            $salesReturn = SalesReturn::create([
                'sale_id' => $sale->id,
                'status' => SalesReturn::STATUS_REQUESTED,
                'reason' => $data['reason'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['items'] as $itemData) {
                $saleItem = $sale->items->firstWhere('id', (int) $itemData['sale_item_id']);

                if (! $saleItem) {
                    throw ValidationException::withMessages([
                        'items' => 'El item no pertenece a la venta indicada.',
                    ]);
                }

                $quantity = (float) $itemData['quantity'];
                $this->ensureReturnableQuantity($saleItem, $quantity);

                $productUnitIds = $itemData['product_unit_ids'] ?? [];
                $this->validateProductUnits($saleItem, $quantity, $productUnitIds);

                SalesReturnItem::create([
                    'sales_return_id' => $salesReturn->id,
                    'sale_item_id' => $saleItem->id,
                    'warehouse_id' => $saleItem->warehouse_id,
                    'product_id' => $saleItem->product_id,
                    'quantity' => $quantity,
                    'product_unit_ids' => $productUnitIds ?: null,
                    'condition' => $itemData['condition'] ?? SalesReturnItem::CONDITION_SELLABLE,
                    'reason' => $itemData['reason'] ?? null,
                ]);
            }

            $salesReturn = $this->loadReturn($salesReturn);
            $this->recordReturnSyncEvent($salesReturn);

            return $salesReturn;
        });
    }

    public function approve(SalesReturn $salesReturn, User $user): SalesReturn
    {
        if ($salesReturn->status !== SalesReturn::STATUS_REQUESTED) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden aprobar devoluciones solicitadas.',
            ]);
        }

        $salesReturn->update([
            'status' => SalesReturn::STATUS_APPROVED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $salesReturn = $this->loadReturn($salesReturn);
        $this->recordReturnSyncEvent($salesReturn);

        return $salesReturn;
    }

    public function reject(SalesReturn $salesReturn, User $user, string $reason): SalesReturn
    {
        if (! in_array($salesReturn->status, [SalesReturn::STATUS_REQUESTED, SalesReturn::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden rechazar devoluciones solicitadas o aprobadas.',
            ]);
        }

        $salesReturn->update([
            'status' => SalesReturn::STATUS_REJECTED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $salesReturn = $this->loadReturn($salesReturn);
        $this->recordReturnSyncEvent($salesReturn);

        return $salesReturn;
    }

    public function cancel(SalesReturn $salesReturn, User $user, string $reason): SalesReturn
    {
        if (in_array($salesReturn->status, [SalesReturn::STATUS_PROCESSED, SalesReturn::STATUS_REJECTED, SalesReturn::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden cancelar devoluciones no procesadas.',
            ]);
        }

        $salesReturn->update([
            'status' => SalesReturn::STATUS_CANCELLED,
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $salesReturn = $this->loadReturn($salesReturn);
        $this->recordReturnSyncEvent($salesReturn);

        return $salesReturn;
    }

    public function process(SalesReturn $salesReturn, User $user, array $data): SalesReturn
    {
        return DB::transaction(function () use ($salesReturn, $user, $data): SalesReturn {
            $salesReturn = SalesReturn::query()
                ->with(['sale.receivable', 'items.saleItem.product', 'items.saleItem.warehouse', 'items.product', 'items.warehouse'])
                ->lockForUpdate()
                ->findOrFail($salesReturn->id);

            if ($salesReturn->status !== SalesReturn::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se pueden procesar devoluciones aprobadas.',
                ]);
            }

            foreach ($salesReturn->items as $returnItem) {
                $saleItem = $returnItem->saleItem;
                $this->ensureReturnableQuantity($saleItem, (float) $returnItem->quantity, $salesReturn->id);
                $this->validateProductUnits($saleItem, (float) $returnItem->quantity, $returnItem->product_unit_ids ?? [], $salesReturn->id);

                $movementData = [
                    'warehouse' => $saleItem->warehouse,
                    'product' => $saleItem->product,
                    'quantity' => (float) $returnItem->quantity,
                    'unitCost' => $saleItem->base_unit_cost === null ? null : (float) $saleItem->base_unit_cost,
                    'createdBy' => $user,
                    'reason' => $returnItem->reason ?? $salesReturn->reason ?? "Devolucion venta #{$salesReturn->sale_id}",
                    'referenceType' => SalesReturn::class,
                    'referenceId' => $salesReturn->id,
                ];

                $movement = $returnItem->condition === SalesReturnItem::CONDITION_DAMAGED
                    ? $this->inventory->damagedSaleReturn(...$movementData)
                    : $this->inventory->saleReturn(...$movementData);

                $returnItem->update(['stock_movement_id' => $movement->id]);
                $this->restoreProductUnits($returnItem->product_unit_ids ?? [], $returnItem->condition, (int) $saleItem->warehouse_id);
            }

            $salesReturn->update([
                'status' => SalesReturn::STATUS_PROCESSED,
                'processed_by' => $user->id,
                'processed_at' => now(),
                'process_notes' => $data['process_notes'] ?? null,
            ]);

            app(AccountsReceivableService::class)->applySalesReturn($salesReturn->refresh());
            $salesReturn = $salesReturn->refresh()->load(['sale.receivable', 'items.saleItem']);
            $adjustment = $this->createCreditNote($salesReturn, $user);
            $this->applyRefund($salesReturn, $user, $data);
            $customerCredit = ($data['refund_mode'] ?? 'none') === 'customer_credit'
                ? $this->issueCustomerCredit($salesReturn, $user)
                : null;
            $salesReturn->update([
                'refund_financial_adjustment_id' => $adjustment->id,
                'customer_credit_transaction_id' => $customerCredit?->id,
            ]);

            $salesReturn = $this->loadReturn($salesReturn);
            $this->recordReturnSyncEvent($salesReturn);

            return $salesReturn;
        });
    }

    public function exchange(SalesReturn $salesReturn, User $user, array $data): SalesReturn
    {
        return DB::transaction(function () use ($salesReturn, $user, $data): SalesReturn {
            $salesReturn = SalesReturn::query()
                ->with(['sale.customer', 'items.saleItem'])
                ->lockForUpdate()
                ->findOrFail($salesReturn->id);

            if ($salesReturn->status !== SalesReturn::STATUS_PROCESSED) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se puede hacer canje sobre una devolucion procesada.',
                ]);
            }

            if (! $salesReturn->sale->customer || ! $salesReturn->customer_credit_transaction_id) {
                throw ValidationException::withMessages([
                    'customer_id' => 'La devolucion no tiene un saldo a favor de cliente disponible.',
                ]);
            }

            $creditAmount = round((float) $data['credit_amount'], 4);
            $availableCredit = $this->customerCredits->availableBase($salesReturn->sale->customer);

            if ($creditAmount > $availableCredit) {
                throw ValidationException::withMessages([
                    'credit_amount' => 'El canje supera el saldo a favor disponible del cliente.',
                ]);
            }

            $payments = $data['payments'] ?? [];
            array_unshift($payments, [
                'method' => 'customer_credit',
                'currency' => Product::CURRENCY_USD,
                'amount' => $creditAmount,
                'status' => 'captured',
            ]);

            $order = $this->posCheckout->checkout(
                cashier: $user,
                cashRegisterSession: CashRegisterSession::query()->findOrFail($data['cash_register_session_id']),
                items: $data['items'],
                payments: $payments,
                customerId: $salesReturn->sale->customer_id,
                customerName: $salesReturn->sale->customer->name,
            );

            if ($order->status !== PosOrder::STATUS_PAID) {
                throw ValidationException::withMessages([
                    'payments' => 'El canje requiere cubrir el total de la nueva venta con el saldo y/o el pago adicional.',
                ]);
            }

            $salesReturn->update(['exchange_sale_id' => $order->sale_id]);

            return $this->loadReturn($salesReturn);
        });
    }

    public function completeExchange(SalesReturn $salesReturn, int $posOrderId): SalesReturn
    {
        return DB::transaction(function () use ($salesReturn, $posOrderId): SalesReturn {
            $salesReturn = SalesReturn::query()
                ->with('sale.customer')
                ->lockForUpdate()
                ->findOrFail($salesReturn->id);

            if ($salesReturn->status !== SalesReturn::STATUS_PROCESSED) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se puede completar un canje sobre una devolucion procesada.',
                ]);
            }

            if (! $salesReturn->sale->customer || ! $salesReturn->customer_credit_transaction_id) {
                throw ValidationException::withMessages([
                    'customer_id' => 'La devolucion no tiene un saldo a favor de cliente disponible.',
                ]);
            }

            if ($salesReturn->exchange_sale_id) {
                throw ValidationException::withMessages([
                    'exchange' => 'La devolucion ya tiene una venta de canje asociada.',
                ]);
            }

            $order = PosOrder::query()
                ->with(['sale', 'payments'])
                ->whereKey($posOrderId)
                ->where('customer_id', $salesReturn->sale->customer_id)
                ->where('status', PosOrder::STATUS_PAID)
                ->lockForUpdate()
                ->first();

            if (! $order || ! $order->sale_id || ! $order->sale) {
                throw ValidationException::withMessages([
                    'pos_order_id' => 'La orden POS debe estar pagada y pertenecer al cliente de la devolucion.',
                ]);
            }

            if (! $order->payments->contains(fn (PosPayment $payment): bool => $payment->method === PosPayment::METHOD_CUSTOMER_CREDIT)) {
                throw ValidationException::withMessages([
                    'pos_order_id' => 'El checkout del canje debe aplicar saldo a favor del cliente.',
                ]);
            }

            $salesReturn->update(['exchange_sale_id' => $order->sale_id]);

            return $this->loadReturn($salesReturn);
        });
    }

    public function loadReturn(SalesReturn $salesReturn): SalesReturn
    {
        return $salesReturn->refresh()->load([
            'sale.customer',
            'sale.receivable',
            'items.saleItem',
            'items.product',
            'items.warehouse',
            'items.stockMovement',
            'refundFinancialAdjustment',
            'creator',
            'reviewer',
            'processor',
            'canceller',
        ]);
    }

    private function recordReturnSyncEvent(SalesReturn $salesReturn): void
    {
        $salesReturn->loadMissing([
            'sale.receivable',
            'items.saleItem',
            'items.product',
            'items.warehouse',
        ]);

        $unitIds = $salesReturn->items
            ->flatMap(fn (SalesReturnItem $item) => $item->product_unit_ids ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $units = ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->get()
            ->keyBy('id');
        $sale = $salesReturn->sale;
        $receivable = $sale->receivable;

        $this->syncOutbox->record(
            eventType: 'sales_return.updated',
            aggregateType: SalesReturn::class,
            aggregateId: $salesReturn->id,
            payload: [
                'return' => [
                    'id' => $salesReturn->id,
                    'status' => $salesReturn->status,
                    'reason' => $salesReturn->reason,
                    'reviewed_at' => $salesReturn->reviewed_at?->toISOString(),
                    'rejection_reason' => $salesReturn->rejection_reason,
                    'processed_at' => $salesReturn->processed_at?->toISOString(),
                    'cancelled_at' => $salesReturn->cancelled_at?->toISOString(),
                    'cancellation_reason' => $salesReturn->cancellation_reason,
                    'process_notes' => $salesReturn->process_notes,
                ],
                'sale' => [
                    'id' => $sale->sync_source_id ?? $sale->id,
                    'source_node_code' => $sale->sync_source_node_code,
                ],
                'items' => $salesReturn->items->map(function (SalesReturnItem $item) use ($units): array {
                    $saleItem = $item->saleItem;
                    $productUnits = collect($item->product_unit_ids ?? [])
                        ->map(fn (int $unitId): ?array => ($unit = $units->get($unitId)) ? [
                            'serial_type' => $unit->serial_type,
                            'serial_number' => $unit->serial_number,
                        ] : null)
                        ->filter()
                        ->values()
                        ->all();

                    return [
                        'id' => $item->id,
                        'sale_item_id' => $saleItem->sync_source_id ?? $saleItem->id,
                        'sale_item_source_node_code' => $saleItem->sync_source_node_code,
                        'product_sku' => $item->product->sku,
                        'warehouse_code' => $item->warehouse->code,
                        'quantity' => (float) $item->quantity,
                        'condition' => $item->condition,
                        'reason' => $item->reason,
                        'product_serial_units' => $productUnits,
                    ];
                })->values()->all(),
                'receivable' => $receivable ? [
                    'status' => $receivable->status,
                    'returned_base_amount' => (float) $receivable->returned_base_amount,
                    'returned_local_amount' => (float) $receivable->returned_local_amount,
                    'balance_base_amount' => (float) $receivable->balance_base_amount,
                    'balance_local_amount' => (float) $receivable->balance_local_amount,
                ] : null,
            ],
            idempotencyKey: "sales_return:{$salesReturn->id}:{$salesReturn->status}",
        );
    }

    private function ensureReturnableQuantity(SaleItem $saleItem, float $quantity, ?int $ignoreSalesReturnId = null): void
    {
        $alreadyReturned = (float) SalesReturnItem::query()
            ->whereHas('salesReturn', function ($query) use ($ignoreSalesReturnId): void {
                $query->whereIn('status', self::RESERVED_RETURN_STATUSES);

                if ($ignoreSalesReturnId) {
                    $query->where('id', '!=', $ignoreSalesReturnId);
                }
            })
            ->where('sale_item_id', $saleItem->id)
            ->sum('quantity');

        $available = (float) $saleItem->quantity - $alreadyReturned;

        if ($quantity > $available) {
            throw ValidationException::withMessages([
                'items' => "La cantidad a devolver supera lo disponible para el item {$saleItem->id}.",
            ]);
        }
    }

    private function validateProductUnits(SaleItem $saleItem, float $quantity, array $productUnitIds, ?int $ignoreSalesReturnId = null): void
    {
        $product = $saleItem->product;

        if (! $product->requiresSerializedTracking()) {
            if ($productUnitIds !== []) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Solo los productos serializados pueden devolver unidades especificas.',
                ]);
            }

            return;
        }

        if (count($productUnitIds) !== (int) $quantity || $quantity !== floor($quantity)) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Los productos serializados requieren una unidad por cada cantidad devuelta.',
            ]);
        }

        if (count($productUnitIds) !== count(array_unique($productUnitIds))) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'No se puede repetir la misma unidad en una devolucion.',
            ]);
        }

        $units = ProductUnit::query()
            ->whereIn('id', $productUnitIds)
            ->get();

        if ($units->count() !== count($productUnitIds)) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Una o mas unidades no existen en la empresa actual.',
            ]);
        }

        foreach ($units as $unit) {
            if ((int) $unit->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Una o mas unidades no pertenecen al producto devuelto.',
                ]);
            }
        }

        $soldUnitIds = $saleItem->product_unit_ids ?? [];
        $foreignUnitIds = array_diff($productUnitIds, $soldUnitIds);

        if ($foreignUnitIds !== []) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Solo se pueden devolver IMEIs o seriales registrados en el item vendido.',
            ]);
        }

        $alreadyRequested = SalesReturnItem::query()
            ->whereHas('salesReturn', function ($query) use ($ignoreSalesReturnId): void {
                $query->whereIn('status', self::RESERVED_RETURN_STATUSES);

                if ($ignoreSalesReturnId) {
                    $query->where('id', '!=', $ignoreSalesReturnId);
                }
            })
            ->where('sale_item_id', $saleItem->id)
            ->get()
            ->flatMap(fn (SalesReturnItem $item) => $item->product_unit_ids ?? [])
            ->all();

        if (array_intersect($productUnitIds, $alreadyRequested) !== []) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Una o mas unidades ya tienen una devolucion abierta o procesada.',
            ]);
        }
    }

    private function restoreProductUnits(array $productUnitIds, string $condition, int $warehouseId): void
    {
        if ($productUnitIds === []) {
            return;
        }

        $status = $condition === SalesReturnItem::CONDITION_DAMAGED
            ? ProductUnit::STATUS_DAMAGED
            : ProductUnit::STATUS_AVAILABLE;

        ProductUnit::query()
            ->whereIn('id', $productUnitIds)
            ->update([
                'warehouse_id' => $warehouseId,
                'status' => $status,
                'released_stock_movement_id' => null,
            ]);
    }

    private function applyRefund(SalesReturn $salesReturn, User $user, array $data): void
    {
        $mode = $data['refund_mode'] ?? 'none';

        if ($mode === 'none') {
            return;
        }

        if ($mode === 'receivable') {
            return;
        }

        if ($mode === 'customer_credit') {
            return;
        }

        $this->assertRefundData($data);

        if ($mode === 'cash') {
            $session = CashRegisterSession::query()->findOrFail($data['refund_cash_register_session_id']);

            if ((int) $session->cashier_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'refund_cash_register_session_id' => 'Solo puedes reembolsar desde tu caja abierta.',
                ]);
            }

            $movement = $this->cashRegister->recordWarrantyRefund($session, [
                'currency' => $data['refund_currency'],
                'amount' => $data['refund_amount'],
                'method' => $data['refund_method'],
                'exchange_rate_type_id' => $data['refund_exchange_rate_type_id'] ?? null,
                'source_type' => SalesReturn::class,
                'source_id' => $salesReturn->id,
                'reference' => $data['refund_reference'] ?? "DEVOLUCION-{$salesReturn->id}",
                'notes' => $data['process_notes'] ?? "Reembolso devolucion #{$salesReturn->id}",
            ], $user);

            $this->assertRefundAmountWithinReturn($salesReturn, (float) $movement->amount_base);

            $salesReturn->update([
                'refund_currency' => $data['refund_currency'],
                'refund_amount' => $data['refund_amount'],
                'refund_exchange_rate_type_id' => $movement->exchange_rate_type_id,
                'refund_exchange_rate_type_code' => $movement->exchange_rate_type_code,
                'refund_exchange_rate' => $movement->exchange_rate,
                'refund_amount_base' => $movement->amount_base,
                'refund_amount_local' => $movement->amount_local,
                'refund_method' => $data['refund_method'],
                'refund_reference' => $data['refund_reference'] ?? null,
                'refund_cash_register_movement_id' => $movement->id,
            ]);

            return;
        }
    }

    private function createCreditNote(SalesReturn $salesReturn, User $user): FinancialAdjustment
    {
        [$firstSaleItem, $currency, $baseAmount, $localAmount] = $this->returnAmounts($salesReturn);

        return $this->financialAdjustments->createCreditNote($user, $salesReturn->sale->receivable, [
            'account_type' => FinancialAdjustment::ACCOUNT_CUSTOMER_CREDIT,
            'currency' => $currency,
            'amount' => $currency === Product::CURRENCY_VES ? $localAmount : $baseAmount,
            'exchange_rate_type_id' => $firstSaleItem->exchange_rate_type_id,
            'exchange_rate' => $firstSaleItem->exchange_rate,
            'reason' => "Nota de credito por devolucion #{$salesReturn->id}",
            'notes' => $salesReturn->reason ?? 'Nota de credito generada por devolucion procesada.',
            'source_type' => SalesReturn::class,
            'source_id' => $salesReturn->id,
        ]);
    }

    private function issueCustomerCredit(SalesReturn $salesReturn, User $user)
    {
        if (! $salesReturn->sale->customer) {
            throw ValidationException::withMessages([
                'refund_mode' => 'El saldo a favor requiere un cliente registrado en la venta.',
            ]);
        }

        [$firstSaleItem, $currency, $baseAmount, $localAmount] = $this->returnAmounts($salesReturn);

        return $this->customerCredits->issue($salesReturn->sale->customer, $user, [
            'currency' => $currency,
            'amount' => $currency === Product::CURRENCY_VES ? $localAmount : $baseAmount,
            'amount_base' => $baseAmount,
            'amount_local' => $localAmount,
            'source_type' => SalesReturn::class,
            'source_id' => $salesReturn->id,
            'notes' => "Saldo a favor por devolucion #{$salesReturn->id}",
        ]);
    }

    private function returnAmounts(SalesReturn $salesReturn): array
    {
        $firstSaleItem = $salesReturn->items->first()->saleItem;
        $baseAmount = 0.0;
        $localAmount = 0.0;

        foreach ($salesReturn->items as $returnItem) {
            $saleItem = $returnItem->saleItem;
            $lineQuantity = (float) $saleItem->quantity;

            if ($lineQuantity <= 0.0) {
                continue;
            }

            $lineBase = round((float) $saleItem->base_total_amount / $lineQuantity * (float) $returnItem->quantity, 4);
            $baseAmount += $lineBase;
            $localAmount += $saleItem->exchange_rate
                ? round($lineBase * (float) $saleItem->exchange_rate, 4)
                : ($saleItem->sale_currency === Product::CURRENCY_VES ? round((float) $saleItem->unit_price * (float) $returnItem->quantity, 4) : 0.0);
        }

        return [$firstSaleItem, $firstSaleItem->sale_currency, round($baseAmount, 4), round($localAmount, 4)];
    }

    private function assertRefundData(array $data): void
    {
        foreach (['refund_currency', 'refund_amount'] as $field) {
            if (! isset($data[$field])) {
                throw ValidationException::withMessages([
                    $field => 'El proceso con reembolso requiere moneda y monto.',
                ]);
            }
        }

        foreach (['refund_cash_register_session_id', 'refund_method'] as $field) {
            if (! isset($data[$field])) {
                throw ValidationException::withMessages([
                    $field => 'El reembolso por caja requiere caja abierta y metodo.',
                ]);
            }
        }
    }

    private function assertRefundAmountWithinReturn(SalesReturn $salesReturn, float $amountBase): void
    {
        $maxRefundBase = 0.0;

        foreach ($salesReturn->items as $returnItem) {
            $saleItem = $returnItem->saleItem;
            $quantity = (float) $saleItem->quantity;

            if ($quantity <= 0.0) {
                continue;
            }

            $maxRefundBase += round(((float) $saleItem->base_total_amount / $quantity) * (float) $returnItem->quantity, 4);
        }

        $collectedBase = (float) ($salesReturn->sale->receivable?->collected_base_amount ?? 0);
        $previousRefundedBase = (float) SalesReturn::query()
            ->where('sale_id', $salesReturn->sale_id)
            ->where('status', SalesReturn::STATUS_PROCESSED)
            ->where('id', '!=', $salesReturn->id)
            ->sum('refund_amount_base');
        $maxRefundBase = min($maxRefundBase, max(0, round($collectedBase - $previousRefundedBase, 4)));

        if ($amountBase > round($maxRefundBase, 4)) {
            throw ValidationException::withMessages([
                'refund_amount' => 'El reembolso supera el monto cobrado disponible para esta venta o el monto devuelto aprobado.',
            ]);
        }
    }
}
