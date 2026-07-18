<?php

namespace App\Modules\SalesReturns\Services;

use App\Models\User;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Models\SalesReturnItem;
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

            return $this->loadReturn($salesReturn);
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

        return $this->loadReturn($salesReturn);
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

        return $this->loadReturn($salesReturn);
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

        return $this->loadReturn($salesReturn);
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

                $movement = $this->inventory->saleReturn(
                    warehouse: $saleItem->warehouse,
                    product: $saleItem->product,
                    quantity: (float) $returnItem->quantity,
                    createdBy: $user,
                    reason: $returnItem->reason ?? $salesReturn->reason ?? "Devolucion venta #{$salesReturn->sale_id}",
                    referenceType: SalesReturn::class,
                    referenceId: $salesReturn->id,
                );

                $returnItem->update(['stock_movement_id' => $movement->id]);
                $this->restoreProductUnits($returnItem->product_unit_ids ?? [], $returnItem->condition);
            }

            app(AccountsReceivableService::class)->applySalesReturn($salesReturn->refresh());
            $this->applyRefund($salesReturn->refresh()->load(['sale.receivable', 'items.saleItem']), $user, $data);

            $salesReturn->update([
                'status' => SalesReturn::STATUS_PROCESSED,
                'processed_by' => $user->id,
                'processed_at' => now(),
                'process_notes' => $data['process_notes'] ?? null,
            ]);

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
            'creator',
            'reviewer',
            'processor',
            'canceller',
        ]);
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

    private function restoreProductUnits(array $productUnitIds, string $condition): void
    {
        if ($productUnitIds === []) {
            return;
        }

        $status = $condition === SalesReturnItem::CONDITION_DAMAGED
            ? ProductUnit::STATUS_DAMAGED
            : ProductUnit::STATUS_AVAILABLE;

        ProductUnit::query()
            ->whereIn('id', $productUnitIds)
            ->update(['status' => $status]);
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

        if ($amountBase > round($maxRefundBase, 4)) {
            throw ValidationException::withMessages([
                'refund_amount' => 'El reembolso supera el monto devuelto aprobado.',
            ]);
        }
    }
}
