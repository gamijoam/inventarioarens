<?php

namespace App\Modules\SalesReversals\Services;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Commissions\Services\CommissionLedgerService;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\SalesReversals\Models\SaleReversal;
use App\Modules\Sync\Services\SyncOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReversalService
{
    public function __construct(
        private readonly InventoryMovementService $inventory,
        private readonly CashRegisterService $cashRegister,
        private readonly CommissionLedgerService $commissions,
        private readonly CustomerCreditService $customerCredits,
        private readonly SyncOutboxService $syncOutbox,
    ) {}

    public function reverse(PosOrder $posOrder, User $user, array $data): SaleReversal
    {
        return DB::transaction(function () use ($posOrder, $user, $data): SaleReversal {
            $order = PosOrder::query()
                ->with([
                    'customer',
                    'sale.items.product',
                    'sale.items.warehouse',
                    'sale.items.variant',
                    'sale.customer',
                    'payments',
                ])
                ->lockForUpdate()
                ->findOrFail($posOrder->id);

            if ($order->status !== PosOrder::STATUS_PAID || ! $order->sale || $order->sale->status !== Sale::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'pos_order_id' => 'Solo se pueden revertir ventas POS pagadas y confirmadas.',
                ]);
            }

            if (SaleReversal::query()->where('pos_order_id', $order->id)->exists()) {
                throw ValidationException::withMessages([
                    'pos_order_id' => 'La venta ya tiene una reversión registrada.',
                ]);
            }

            $type = (string) $data['type'];
            $paidAt = $order->paid_at ?? $order->created_at;
            if ($type === SaleReversal::TYPE_VOID && $paidAt && ! $paidAt->isToday()) {
                throw ValidationException::withMessages([
                    'type' => 'Una venta de una fecha anterior debe procesarse como reversal.',
                ]);
            }

            $session = CashRegisterSession::query()
                ->lockForUpdate()
                ->findOrFail($data['cash_register_session_id']);
            if ($session->status !== CashRegisterSession::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'cash_register_session_id' => 'La caja de devolución debe estar abierta.',
                ]);
            }

            $capturedPayments = $order->payments
                ->where('status', PosPayment::STATUS_CAPTURED)
                ->values();
            if ($capturedPayments->isEmpty()) {
                throw ValidationException::withMessages([
                    'payments' => 'La venta no tiene pagos capturados para revertir.',
                ]);
            }

            $reversal = SaleReversal::create([
                'sale_id' => $order->sale_id,
                'pos_order_id' => $order->id,
                'cash_register_session_id' => $session->id,
                'created_by' => $user->id,
                'type' => $type,
                'reason' => $data['reason'],
                'original_paid_at' => $paidAt,
                'effective_at' => now(),
                'reversed_base_amount' => $capturedPayments->sum(fn (PosPayment $payment): float => (float) $payment->amount_base),
                'reversed_local_amount' => $capturedPayments->sum(fn (PosPayment $payment): float => (float) $payment->amount_local),
            ]);

            foreach ($order->sale->items as $item) {
                if (! $item->product->track_stock) {
                    continue;
                }

                $movement = $this->inventory->saleReversal(
                    warehouse: $item->warehouse,
                    product: $item->product,
                    quantity: (float) $item->quantity,
                    createdBy: $user,
                    reason: "Reversión de venta POS #{$order->id}",
                    referenceId: $reversal->id,
                    productVariantId: $item->product_variant_id,
                );

                $unitIds = array_values(array_filter($item->product_unit_ids ?? []));
                if ($unitIds === []) {
                    continue;
                }

                $units = ProductUnit::query()->whereIn('id', $unitIds)->lockForUpdate()->get();
                if ($units->count() !== count($unitIds) || $units->contains(fn (ProductUnit $unit): bool => $unit->status !== ProductUnit::STATUS_SOLD)) {
                    throw ValidationException::withMessages([
                        'items' => 'Uno o más IMEI/seriales ya no están vendidos o no existen.',
                    ]);
                }

                foreach ($units as $unit) {
                    $unit->update([
                        'status' => ProductUnit::STATUS_AVAILABLE,
                        'released_stock_movement_id' => $movement->id,
                    ]);
                }
            }

            foreach ($capturedPayments as $payment) {
                if ($payment->method === PosPayment::METHOD_CUSTOMER_CREDIT) {
                    $customer = $order->customer ?? $order->sale->customer;
                    if (! $customer) {
                        throw ValidationException::withMessages([
                            'payments' => 'El pago con saldo a favor requiere un cliente registrado.',
                        ]);
                    }

                    $this->customerCredits->issue($customer, $user, [
                        'currency' => $payment->currency,
                        'amount' => $payment->amount,
                        'amount_base' => $payment->amount_base,
                        'amount_local' => $payment->amount_local,
                        'source_type' => SaleReversal::class,
                        'source_id' => $reversal->id,
                        'notes' => "Saldo restaurado por reversión de venta POS #{$order->id}",
                    ]);

                    continue;
                }

                if ($payment->method !== PosPayment::METHOD_CASH) {
                    throw ValidationException::withMessages([
                        'payments' => 'Los pagos externos requieren conciliación de reembolso antes de revertir la venta.',
                    ]);
                }

                $this->cashRegister->recordSaleReversal($session, $payment, $reversal, $user);
            }

            $order->update(['status' => PosOrder::STATUS_VOIDED]);
            $order->sale->update(['status' => Sale::STATUS_VOIDED]);
            $this->commissions->reversePosOrder($order, $reversal->id);
            $this->recordSyncEvent($reversal->refresh()->load('sale', 'posOrder'));

            return $reversal->refresh();
        });
    }

    private function recordSyncEvent(SaleReversal $reversal): void
    {
        $this->syncOutbox->record(
            eventType: 'pos.order.reversed',
            aggregateType: 'pos_order',
            aggregateId: $reversal->pos_order_id,
            payload: [
                'reversal_id' => $reversal->id,
                'type' => $reversal->type,
                'reason' => $reversal->reason,
                'sale_id' => $reversal->sale_id,
                'pos_order_id' => $reversal->pos_order_id,
                'status' => PosOrder::STATUS_VOIDED,
                'sale_status' => Sale::STATUS_VOIDED,
                'cash_register_session_id' => $reversal->cash_register_session_id,
                'reversed_base_amount' => (string) $reversal->reversed_base_amount,
                'reversed_local_amount' => (string) $reversal->reversed_local_amount,
                'paid_at' => $reversal->posOrder?->paid_at?->toJSON(),
                'closed_at' => $reversal->posOrder?->closed_at?->toJSON(),
                'sale' => [
                    'id' => $reversal->sale_id,
                    'status' => Sale::STATUS_VOIDED,
                    'total_base_amount' => (string) $reversal->sale?->total_base_amount,
                    'total_local_amount' => (string) $reversal->sale?->total_local_amount,
                    'confirmed_at' => $reversal->sale?->confirmed_at?->toJSON(),
                ],
                'items' => $this->syncItems($reversal->sale),
                'effective_at' => $reversal->effective_at?->toISOString(),
            ],
            idempotencyKey: "pos.order.reversed:{$reversal->pos_order_id}",
        );
    }

    private function syncItems(?Sale $sale): array
    {
        if ($sale === null) {
            return [];
        }

        return $sale->items->map(function ($item): array {
            $unitIds = array_values(array_filter($item->product_unit_ids ?? []));
            $serialUnits = ProductUnit::query()
                ->whereIn('id', $unitIds)
                ->get(['serial_type', 'serial_number'])
                ->map(fn (ProductUnit $unit): array => [
                    'serial_type' => $unit->serial_type,
                    'serial_number' => $unit->serial_number,
                ])
                ->values()
                ->all();

            return [
                'id' => $item->id,
                'product_sku' => $item->product?->sku,
                'warehouse_code' => $item->warehouse?->code,
                'price_list_code' => null,
                'quantity' => (string) $item->quantity,
                'sale_currency' => $item->sale_currency,
                'unit_price' => (string) $item->unit_price,
                'total_amount' => (string) $item->total_amount,
                'base_unit_price' => (string) $item->base_unit_price,
                'base_total_amount' => (string) $item->base_total_amount,
                'exchange_rate_type_code' => $item->exchange_rate_type_code,
                'exchange_rate' => $item->exchange_rate === null ? null : (string) $item->exchange_rate,
                'product_unit_ids' => [],
                'product_serial_units' => $serialUnits,
            ];
        })->values()->all();
    }
}
