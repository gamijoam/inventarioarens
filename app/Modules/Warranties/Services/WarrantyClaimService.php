<?php

namespace App\Modules\Warranties\Services;

use App\Models\User;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\FinancialAdjustments\Services\FinancialAdjustmentService;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warranties\Models\WarrantyClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarrantyClaimService
{
    private const OPEN_STATUSES = [
        WarrantyClaim::STATUS_RECEIVED,
        WarrantyClaim::STATUS_UNDER_REVIEW,
        WarrantyClaim::STATUS_APPROVED,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryMovementService $inventory,
        private readonly CashRegisterService $cashRegister,
        private readonly FinancialAdjustmentService $financialAdjustments,
    )
    {
    }

    public function create(User $user, array $data): WarrantyClaim
    {
        return DB::transaction(function () use ($user, $data): WarrantyClaim {
            $saleItem = SaleItem::query()
                ->with(['sale.customer', 'product'])
                ->lockForUpdate()
                ->findOrFail($data['sale_item_id']);

            $this->assertWarrantyEligible($saleItem);

            $quantity = (float) ($data['quantity'] ?? 1);
            $productUnit = $this->resolveProductUnit($saleItem, $data['product_unit_id'] ?? null, $quantity);
            $this->assertClaimableQuantity($saleItem, $quantity, $productUnit);

            $claim = WarrantyClaim::create([
                'sale_id' => $saleItem->sale_id,
                'sale_item_id' => $saleItem->id,
                'customer_id' => $saleItem->sale->customer_id,
                'product_id' => $saleItem->product_id,
                'product_unit_id' => $productUnit?->id,
                'status' => WarrantyClaim::STATUS_RECEIVED,
                'quantity' => $quantity,
                'customer_name' => $data['customer_name'] ?? $saleItem->sale->customer?->name,
                'customer_phone' => $data['customer_phone'] ?? null,
                'issue_description' => $data['issue_description'],
                'received_notes' => $data['received_notes'] ?? null,
                'received_by' => $user->id,
                'received_at' => now(),
            ]);

            if ($productUnit) {
                $productUnit->update(['status' => ProductUnit::STATUS_WARRANTY_HOLD]);
            }

            $claim = $this->loadClaim($claim);

            $this->audit->record('warranty.claim.received', $claim, $user, null, [
                'sale_id' => $claim->sale_id,
                'sale_item_id' => $claim->sale_item_id,
                'product_id' => $claim->product_id,
                'product_unit_id' => $claim->product_unit_id,
                'status' => $claim->status,
            ]);

            return $claim;
        });
    }

    public function review(WarrantyClaim $claim, User $user, array $data): WarrantyClaim
    {
        if (! in_array($claim->status, [WarrantyClaim::STATUS_RECEIVED, WarrantyClaim::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden revisar garantias recibidas o en revision.',
            ]);
        }

        $oldValues = [
            'status' => $claim->status,
            'diagnosis' => $claim->diagnosis,
            'resolution_type' => $claim->resolution_type,
        ];

        $claim->update([
            'status' => $data['status'],
            'diagnosis' => $data['diagnosis'] ?? $claim->diagnosis,
            'resolution_type' => $data['resolution_type'] ?? null,
            'resolution_notes' => $data['resolution_notes'] ?? null,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $claim = $this->loadClaim($claim);

        $this->audit->record('warranty.claim.reviewed', $claim, $user, $oldValues, [
            'status' => $claim->status,
            'diagnosis' => $claim->diagnosis,
            'resolution_type' => $claim->resolution_type,
        ]);

        return $claim;
    }

    public function deliver(WarrantyClaim $claim, User $user, ?string $notes = null): WarrantyClaim
    {
        if (! in_array($claim->status, [WarrantyClaim::STATUS_APPROVED, WarrantyClaim::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden entregar garantias aprobadas o rechazadas.',
            ]);
        }

        $oldStatus = $claim->status;

        $claim->update([
            'status' => WarrantyClaim::STATUS_DELIVERED,
            'resolution_notes' => $notes ?? $claim->resolution_notes,
            'delivered_by' => $user->id,
            'delivered_at' => now(),
        ]);

        $claim = $this->loadClaim($claim);

        $this->audit->record('warranty.claim.delivered', $claim, $user, [
            'status' => $oldStatus,
        ], [
            'status' => $claim->status,
        ]);

        return $claim;
    }

    public function resolve(WarrantyClaim $claim, User $user, array $data): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $user, $data): WarrantyClaim {
            $claim = WarrantyClaim::query()
                ->with(['product', 'productUnit', 'saleItem.product'])
                ->lockForUpdate()
                ->findOrFail($claim->id);

            if ($claim->resolved_at !== null || $claim->status === WarrantyClaim::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'status' => 'La garantia ya fue resuelta.',
                ]);
            }

            return match ($data['resolution_type']) {
                WarrantyClaim::RESOLUTION_REPLACEMENT => $this->resolveReplacement($claim, $user, $data),
                WarrantyClaim::RESOLUTION_REFUND => $this->resolveRefund($claim, $user, $data),
                WarrantyClaim::RESOLUTION_REJECTED => $this->resolveRejection($claim, $user, $data['resolution_notes'] ?? null),
                default => throw ValidationException::withMessages([
                    'resolution_type' => 'Tipo de resolucion no soportado en esta fase.',
                ]),
            };
        });
    }

    public function loadClaim(WarrantyClaim $claim): WarrantyClaim
    {
        return $claim->refresh()->load([
            'sale.customer',
            'saleItem.product',
            'product',
            'productUnit',
            'replacementProductUnit',
            'replacementStockMovement',
            'refundCashRegisterMovement',
            'refundFinancialAdjustment',
            'receiver',
            'reviewer',
            'deliverer',
            'resolver',
        ]);
    }

    private function resolveReplacement(WarrantyClaim $claim, User $user, array $data): WarrantyClaim
    {
        if ($claim->status !== WarrantyClaim::STATUS_APPROVED || $claim->resolution_type !== WarrantyClaim::RESOLUTION_REPLACEMENT) {
            throw ValidationException::withMessages([
                'status' => 'El caso debe estar aprobado con resolucion de reemplazo.',
            ]);
        }

        $replacementUnit = $this->resolveReplacementProductUnit($claim, $data['replacement_product_unit_id'] ?? null);
        $this->assertReplacementWarehouseData($claim, $data);
        $warehouse = $replacementUnit?->warehouse
            ?? Warehouse::query()->findOrFail($data['replacement_warehouse_id']);

        try {
            $movement = $this->inventory->adjustmentOut(
                warehouse: $warehouse,
                product: $claim->product,
                quantity: (float) $claim->quantity,
                createdBy: $user,
                reason: "Reemplazo garantia #{$claim->id}",
                referenceType: WarrantyClaim::class,
                referenceId: $claim->id,
            );
        } catch (InsufficientStockException) {
            throw ValidationException::withMessages([
                'replacement_warehouse_id' => 'Stock insuficiente para entregar el reemplazo.',
            ]);
        }

        if ($claim->productUnit) {
            $claim->productUnit->update(['status' => ProductUnit::STATUS_DAMAGED]);
        }

        if ($replacementUnit) {
            $replacementUnit->update([
                'status' => ProductUnit::STATUS_SOLD,
                'released_stock_movement_id' => $movement->id,
            ]);
        }

        $oldValues = [
            'status' => $claim->status,
            'replacement_product_unit_id' => $claim->replacement_product_unit_id,
            'replacement_stock_movement_id' => $claim->replacement_stock_movement_id,
        ];

        $claim->update([
            'status' => WarrantyClaim::STATUS_CLOSED,
            'replacement_product_unit_id' => $replacementUnit?->id,
            'replacement_stock_movement_id' => $movement->id,
            'resolution_notes' => $data['resolution_notes'] ?? $claim->resolution_notes,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'delivered_by' => $user->id,
            'delivered_at' => now(),
        ]);

        $claim = $this->loadClaim($claim);

        $this->audit->record('warranty.claim.resolved', $claim, $user, $oldValues, [
            'status' => $claim->status,
            'resolution_type' => $claim->resolution_type,
            'replacement_product_unit_id' => $claim->replacement_product_unit_id,
            'replacement_stock_movement_id' => $claim->replacement_stock_movement_id,
        ]);

        return $claim;
    }

    private function resolveRefund(WarrantyClaim $claim, User $user, array $data): WarrantyClaim
    {
        if ($claim->status !== WarrantyClaim::STATUS_APPROVED || $claim->resolution_type !== WarrantyClaim::RESOLUTION_REFUND) {
            throw ValidationException::withMessages([
                'status' => 'El caso debe estar aprobado con resolucion de reembolso.',
            ]);
        }

        $this->assertRefundData($data);

        $movement = null;
        $adjustment = null;
        $amounts = null;

        if (! empty($data['refund_cash_register_session_id'])) {
            $session = CashRegisterSession::query()->findOrFail($data['refund_cash_register_session_id']);
            $movement = $this->cashRegister->recordWarrantyRefund($session, [
                'currency' => $data['refund_currency'],
                'amount' => $data['refund_amount'],
                'method' => $data['refund_method'],
                'exchange_rate_type_id' => $data['refund_exchange_rate_type_id'] ?? null,
                'source_type' => WarrantyClaim::class,
                'source_id' => $claim->id,
                'reference' => $data['refund_reference'] ?? "GARANTIA-{$claim->id}",
                'notes' => $data['resolution_notes'] ?? "Reembolso garantia #{$claim->id}",
            ], $user);
            $amounts = [
                'exchange_rate_type_id' => $movement->exchange_rate_type_id,
                'exchange_rate_type_code' => $movement->exchange_rate_type_code,
                'exchange_rate' => $movement->exchange_rate === null ? null : (float) $movement->exchange_rate,
                'amount_base' => (float) $movement->amount_base,
                'amount_local' => $movement->amount_local === null ? 0.0 : (float) $movement->amount_local,
            ];
        } else {
            $account = AccountsReceivable::query()
                ->where('sale_id', $claim->sale_id)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw ValidationException::withMessages([
                    'apply_to_receivable_balance' => 'La venta no tiene cuenta por cobrar para ajustar.',
                ]);
            }

            $adjustment = $this->financialAdjustments->create($user, [
                'account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE,
                'account_id' => $account->id,
                'currency' => $data['refund_currency'],
                'amount' => $data['refund_amount'],
                'exchange_rate_type_id' => $data['refund_exchange_rate_type_id'] ?? null,
                'exchange_rate' => isset($data['refund_exchange_rate']) ? (float) $data['refund_exchange_rate'] : null,
                'reason' => "Reembolso garantia #{$claim->id}",
                'notes' => $data['resolution_notes'] ?? 'Reembolso aplicado al saldo pendiente por garantia.',
            ]);
            $amounts = [
                'exchange_rate_type_id' => $adjustment->exchange_rate_type_id,
                'exchange_rate_type_code' => $adjustment->exchange_rate_type_code,
                'exchange_rate' => $adjustment->exchange_rate === null ? null : (float) $adjustment->exchange_rate,
                'amount_base' => (float) $adjustment->amount_base,
                'amount_local' => (float) $adjustment->amount_local,
            ];
        }

        $this->assertRefundAmountWithinSaleItem($claim, $amounts['amount_base']);

        if ($claim->productUnit) {
            $claim->productUnit->update(['status' => ProductUnit::STATUS_DAMAGED]);
        }

        $oldValues = [
            'status' => $claim->status,
            'refund_cash_register_movement_id' => $claim->refund_cash_register_movement_id,
            'refund_financial_adjustment_id' => $claim->refund_financial_adjustment_id,
        ];

        $claim->update([
            'status' => WarrantyClaim::STATUS_CLOSED,
            'refund_currency' => $data['refund_currency'],
            'refund_amount' => $data['refund_amount'],
            'refund_exchange_rate_type_id' => $amounts['exchange_rate_type_id'],
            'refund_exchange_rate_type_code' => $amounts['exchange_rate_type_code'],
            'refund_exchange_rate' => $amounts['exchange_rate'],
            'refund_amount_base' => $amounts['amount_base'],
            'refund_amount_local' => $amounts['amount_local'],
            'refund_method' => $data['refund_method'] ?? null,
            'refund_reference' => $data['refund_reference'] ?? null,
            'refund_cash_register_movement_id' => $movement?->id,
            'refund_financial_adjustment_id' => $adjustment?->id,
            'resolution_notes' => $data['resolution_notes'] ?? $claim->resolution_notes,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'delivered_by' => $user->id,
            'delivered_at' => now(),
        ]);

        $claim = $this->loadClaim($claim);

        $this->audit->record('warranty.claim.resolved', $claim, $user, $oldValues, [
            'status' => $claim->status,
            'resolution_type' => $claim->resolution_type,
            'refund_amount_base' => $claim->refund_amount_base === null ? null : (float) $claim->refund_amount_base,
            'refund_cash_register_movement_id' => $claim->refund_cash_register_movement_id,
            'refund_financial_adjustment_id' => $claim->refund_financial_adjustment_id,
        ]);

        return $claim;
    }

    private function resolveRejection(WarrantyClaim $claim, User $user, ?string $notes): WarrantyClaim
    {
        if (! in_array($claim->status, [WarrantyClaim::STATUS_REJECTED, WarrantyClaim::STATUS_APPROVED], true)
            || ! in_array($claim->resolution_type, [WarrantyClaim::RESOLUTION_REJECTED, null], true)) {
            throw ValidationException::withMessages([
                'status' => 'El caso debe estar rechazado o aprobado como rechazo.',
            ]);
        }

        if ($claim->productUnit && $claim->productUnit->status === ProductUnit::STATUS_WARRANTY_HOLD) {
            $claim->productUnit->update(['status' => ProductUnit::STATUS_SOLD]);
        }

        $oldStatus = $claim->status;

        $claim->update([
            'status' => WarrantyClaim::STATUS_CLOSED,
            'resolution_type' => WarrantyClaim::RESOLUTION_REJECTED,
            'resolution_notes' => $notes ?? $claim->resolution_notes,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'delivered_by' => $user->id,
            'delivered_at' => now(),
        ]);

        $claim = $this->loadClaim($claim);

        $this->audit->record('warranty.claim.resolved', $claim, $user, [
            'status' => $oldStatus,
        ], [
            'status' => $claim->status,
            'resolution_type' => $claim->resolution_type,
        ]);

        return $claim;
    }

    private function resolveReplacementProductUnit(WarrantyClaim $claim, ?int $replacementProductUnitId): ?ProductUnit
    {
        if (! $claim->product->requiresSerializedTracking()) {
            if ($replacementProductUnitId !== null) {
                throw ValidationException::withMessages([
                    'replacement_product_unit_id' => 'Solo los productos serializados pueden reemplazarse con IMEI o serial especifico.',
                ]);
            }

            return null;
        }

        if ($replacementProductUnitId === null || (float) $claim->quantity !== 1.0) {
            throw ValidationException::withMessages([
                'replacement_product_unit_id' => 'El reemplazo serializado requiere una unidad disponible especifica.',
            ]);
        }

        $unit = ProductUnit::query()->with('warehouse')->lockForUpdate()->findOrFail($replacementProductUnitId);

        if ((int) $unit->product_id !== (int) $claim->product_id) {
            throw ValidationException::withMessages([
                'replacement_product_unit_id' => 'La unidad de reemplazo no pertenece al producto del caso.',
            ]);
        }

        if ((int) $unit->id === (int) $claim->product_unit_id) {
            throw ValidationException::withMessages([
                'replacement_product_unit_id' => 'La unidad recibida por garantia no puede ser su propio reemplazo.',
            ]);
        }

        if ($unit->status !== ProductUnit::STATUS_AVAILABLE) {
            throw ValidationException::withMessages([
                'replacement_product_unit_id' => 'La unidad de reemplazo no esta disponible.',
            ]);
        }

        return $unit;
    }

    private function assertReplacementWarehouseData(WarrantyClaim $claim, array $data): void
    {
        if ($claim->product->requiresSerializedTracking()) {
            return;
        }

        if (! isset($data['replacement_warehouse_id'])) {
            throw ValidationException::withMessages([
                'replacement_warehouse_id' => 'El reemplazo por cantidad requiere almacen de salida.',
            ]);
        }
    }

    private function assertRefundData(array $data): void
    {
        foreach (['refund_currency', 'refund_amount'] as $field) {
            if (! isset($data[$field])) {
                throw ValidationException::withMessages([
                    $field => 'El reembolso requiere moneda y monto.',
                ]);
            }
        }

        $cashRegisterSessionId = $data['refund_cash_register_session_id'] ?? null;
        $applyToReceivable = (bool) ($data['apply_to_receivable_balance'] ?? false);

        if ($cashRegisterSessionId && $applyToReceivable) {
            throw ValidationException::withMessages([
                'refund_cash_register_session_id' => 'El reembolso no puede salir de caja y rebajar saldo al mismo tiempo.',
            ]);
        }

        if (! $cashRegisterSessionId && ! $applyToReceivable) {
            throw ValidationException::withMessages([
                'refund_cash_register_session_id' => 'Indique una caja abierta o aplique el reembolso al saldo pendiente.',
            ]);
        }

        if ($cashRegisterSessionId && ! isset($data['refund_method'])) {
            throw ValidationException::withMessages([
                'refund_method' => 'El reembolso por caja requiere metodo de pago.',
            ]);
        }
    }

    private function assertRefundAmountWithinSaleItem(WarrantyClaim $claim, float $amountBase): void
    {
        $saleItem = $claim->saleItem;
        $lineQuantity = (float) $saleItem->quantity;
        $lineTotalBase = (float) $saleItem->base_total_amount;

        if ($lineQuantity <= 0.0) {
            throw ValidationException::withMessages([
                'sale_item_id' => 'El item vendido no tiene cantidad valida para calcular reembolso.',
            ]);
        }

        $perUnitBase = $lineTotalBase / $lineQuantity;
        $maxRefundBase = round($perUnitBase * (float) $claim->quantity, 4);

        if ($amountBase > $maxRefundBase) {
            throw ValidationException::withMessages([
                'refund_amount' => 'El reembolso supera el monto vendido para este item.',
            ]);
        }
    }

    private function assertWarrantyEligible(SaleItem $saleItem): void
    {
        if ($saleItem->sale->status !== Sale::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'sale_item_id' => 'Solo se pueden crear garantias sobre ventas confirmadas.',
            ]);
        }

        if ($saleItem->warranty_policy_id === null || $saleItem->warranty_expires_at === null) {
            throw ValidationException::withMessages([
                'sale_item_id' => 'El item vendido no tiene garantia registrada.',
            ]);
        }

        if ($saleItem->warranty_expires_at->lt(now())) {
            throw ValidationException::withMessages([
                'sale_item_id' => 'La garantia del item vendido ya vencio.',
            ]);
        }
    }

    private function resolveProductUnit(SaleItem $saleItem, ?int $productUnitId, float $quantity): ?ProductUnit
    {
        if (! $saleItem->product->requiresSerializedTracking()) {
            if ($productUnitId !== null) {
                throw ValidationException::withMessages([
                    'product_unit_id' => 'Solo los productos serializados pueden asociar IMEI o serial.',
                ]);
            }

            return null;
        }

        if ($productUnitId === null || $quantity !== 1.0) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'La garantia de un producto serializado requiere una unidad especifica.',
            ]);
        }

        $unit = ProductUnit::query()->lockForUpdate()->findOrFail($productUnitId);

        if ((int) $unit->product_id !== (int) $saleItem->product_id) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'La unidad no pertenece al producto vendido.',
            ]);
        }

        if (! in_array($unit->status, [ProductUnit::STATUS_SOLD, ProductUnit::STATUS_AVAILABLE], true)) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'La unidad no esta en un estado valido para garantia.',
            ]);
        }

        if (! in_array($unit->id, $saleItem->product_unit_ids ?? [], true)) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'La unidad no esta registrada como vendida en este item.',
            ]);
        }

        return $unit;
    }

    private function assertClaimableQuantity(SaleItem $saleItem, float $quantity, ?ProductUnit $productUnit): void
    {
        if ($quantity <= 0 || $quantity > (float) $saleItem->quantity) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad de garantia no es valida para el item vendido.',
            ]);
        }

        $query = WarrantyClaim::query()
            ->where('sale_item_id', $saleItem->id)
            ->whereIn('status', self::OPEN_STATUSES);

        if ($productUnit) {
            if ((clone $query)->where('product_unit_id', $productUnit->id)->exists()) {
                throw ValidationException::withMessages([
                    'product_unit_id' => 'Ya existe una garantia abierta para esta unidad.',
                ]);
            }

            return;
        }

        $openQuantity = (float) $query->sum('quantity');

        if ($openQuantity + $quantity > (float) $saleItem->quantity) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad supera lo disponible para garantias abiertas.',
            ]);
        }
    }
}
