<?php

namespace App\Modules\Workshop\Services;

use App\Models\User;
use App\Modules\Commissions\Services\CommissionLedgerService;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warranties\Models\WarrantyClaim;
use App\Modules\Warranties\Services\WarrantyClaimService;
use App\Modules\Workshop\Models\ServiceOrder;
use App\Modules\Workshop\Models\ServiceOrderPart;
use App\Modules\Workshop\Models\ServiceOrderStatusHistory;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Orquesta el ciclo de vida de una orden de servicio del Taller:
 * recepcion -> diagnostico -> asignar tecnico -> piezas -> completar (descuenta
 * inventario) -> entregar/cerrar/cancelar.
 */
class ServiceOrderService
{
    public function __construct(
        private readonly InventoryMovementService $inventory,
        private readonly CommissionLedgerService $commissions,
        private readonly WarrantyClaimService $warranties,
        private readonly TenantManager $tenants,
    ) {}

    public function create(User $operator, array $data): ServiceOrder
    {
        $tenantId = $this->tenants->require()->id;

        return DB::transaction(function () use ($operator, $data, $tenantId): ServiceOrder {
            // Si es garantia, validar duplicados y marcarla como enviada al taller
            // ANTES de crear la orden (si no, el chequeo encontraria la propia orden).
            if (! empty($data['warranty_claim_id'])) {
                $claim = WarrantyClaim::query()->findOrFail($data['warranty_claim_id']);
                $this->warranties->sendToWorkshop($claim, $operator);
            }

            $order = ServiceOrder::create([
                ...$data,
                'tenant_id' => $tenantId,
                'order_number' => ServiceOrder::nextOrderNumber(),
                'status' => ServiceOrder::STATUS_RECEIVED,
                'created_by' => $operator->id,
                'received_at' => now(),
            ]);

            $this->recordTransition($order, ServiceOrder::STATUS_RECEIVED, $operator);

            return $order->fresh(['warehouse', 'technician', 'parts']);
        });
    }

    public function update(ServiceOrder $order, User $operator, array $data): ServiceOrder
    {
        $this->assertNotClosed($order);

        $order->update($data);

        return $order->fresh(['warehouse', 'technician', 'parts']);
    }

    public function diagnose(ServiceOrder $order, User $operator, array $data): ServiceOrder
    {
        $this->assertNotClosed($order);

        return DB::transaction(function () use ($order, $operator, $data): ServiceOrder {
            $order->update([
                'diagnosis' => $data['diagnosis'],
                'labor_base_amount' => (float) ($data['labor_base_amount'] ?? 0),
                'labor_local_amount' => (float) ($data['labor_local_amount'] ?? 0),
            ]);

            if ($order->status === ServiceOrder::STATUS_RECEIVED) {
                $this->transition($order, ServiceOrder::STATUS_DIAGNOSED, $operator);
            }

            $order->refresh();
            $this->recomputeTotals($order);

            return $order->fresh(['warehouse', 'technician', 'parts']);
        });
    }

    public function assignTechnician(ServiceOrder $order, User $operator, array $data): ServiceOrder
    {
        $this->assertNotClosed($order);

        return DB::transaction(function () use ($order, $data): ServiceOrder {
            $technician = User::query()->findOrFail($data['technician_id']);

            if (! $technician->belongsToTenant($this->tenants->require())) {
                throw ValidationException::withMessages([
                    'technician_id' => 'El tecnico no pertenece a la empresa actual.',
                ]);
            }

            $order->update([
                'technician_id' => $technician->id,
                'warehouse_id' => $data['warehouse_id'],
                'technician_assigned_at' => $order->technician_assigned_at ?? now(),
            ]);

            return $order->fresh(['warehouse', 'technician', 'parts']);
        });
    }

    public function addPart(ServiceOrder $order, User $operator, array $data): ServiceOrderPart
    {
        $this->assertNotClosed($order);
        $tenantId = $this->tenants->require()->id;

        return DB::transaction(function () use ($order, $operator, $data): ServiceOrderPart {
            $warehouseId = (int) ($data['warehouse_id'] ?? $order->warehouse_id);
            $warehouse = Warehouse::query()->findOrFail($warehouseId);

            $product = Product::query()->findOrFail($data['product_id']);
            $quantity = (float) $data['quantity'];

            $available = (float) (StockBalance::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $product->id)
                ->value('quantity_available') ?? 0);
            if ($available < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Stock insuficiente: hay {$available} disponibles de {$product->name}.",
                ]);
            }

            $unitCost = (float) ($product->last_purchase_cost ?? $product->average_cost ?? 0);
            $unitPrice = (float) $product->base_price ?? 0;

            $part = ServiceOrderPart::create([
                'service_order_id' => $order->id,
                'product_id' => $product->id,
                'product_variant_id' => $data['product_variant_id'] ?? null,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'unit_price' => $unitPrice,
                'base_unit_price' => $unitPrice,
                'base_unit_cost' => $unitCost,
                'status' => ServiceOrder::PART_STATUS_PENDING,
                'created_by' => $operator->id,
            ]);

            $order->refresh();
            $this->recomputeTotals($order);

            return $part->fresh(['product', 'warehouse']);
        });
    }

    public function removePart(ServiceOrder $order, User $operator, ServiceOrderPart $part): void
    {
        $this->assertNotClosed($order);

        DB::transaction(function () use ($order, $part): void {
            if ($part->status !== ServiceOrder::PART_STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'part' => 'Solo se pueden quitar piezas pendientes.',
                ]);
            }

            $part->delete();
            $order->refresh();
            $this->recomputeTotals($order);
        });
    }

    /**
     * Completa la reparacion: descuenta del inventario las piezas pendientes
     * (con su costo) y entrega/cierra la orden.
     */
    public function complete(ServiceOrder $order, User $operator): ServiceOrder
    {
        $this->assertNotClosed($order);

        if (! in_array($order->status, [
            ServiceOrder::STATUS_DIAGNOSED,
            ServiceOrder::STATUS_IN_PROGRESS,
            ServiceOrder::STATUS_READY,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'La orden debe estar diagnosticada o en reparacion para completarse.',
            ]);
        }

        return DB::transaction(function () use ($order, $operator): ServiceOrder {
            foreach ($order->parts()->where('status', ServiceOrder::PART_STATUS_PENDING)->get() as $part) {
                $warehouse = Warehouse::query()->findOrFail($part->warehouse_id);
                $product = Product::query()->findOrFail($part->product_id);

                $movement = $this->inventory->serviceExit(
                    warehouse: $warehouse,
                    product: $product,
                    quantity: (float) $part->quantity,
                    unitCost: (float) $part->unit_cost,
                    createdBy: $operator,
                    reason: "Pieza de orden de servicio {$order->order_number}",
                    referenceType: ServiceOrder::class,
                    referenceId: $order->id,
                    productVariantId: $part->product_variant_id,
                );

                $part->update([
                    'status' => ServiceOrder::PART_STATUS_CONSUMED,
                    'stock_movement_id' => $movement->id,
                ]);
            }

            $this->transition($order, ServiceOrder::STATUS_DELIVERED, $operator);

            $order->refresh();
            $this->recomputeTotals($order);

            // Comision del tecnico (si el plan rol technician aplica).
            $this->commissions->recordServiceOrder($order->fresh());

            // Si es garantia, resolverla segun el tratamiento de la orden.
            if ($order->warranty_claim_id) {
                $claim = WarrantyClaim::query()->findOrFail($order->warranty_claim_id);
                $this->warranties->resolveFromWorkshop($claim, $order->fresh(), $operator);
            }

            return $order->fresh(['warehouse', 'technician', 'parts', 'parts.product']);
        });
    }

    public function cancel(ServiceOrder $order, User $operator): ServiceOrder
    {
        $this->assertNotClosed($order);

        if (! $order->canTransitionTo(ServiceOrder::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => 'Esta orden ya no puede cancelarse.',
            ]);
        }

        DB::transaction(function () use ($order, $operator): void {
            $this->transition($order, ServiceOrder::STATUS_CANCELLED, $operator);

            // Devolver la garantia a received si estaba en el taller.
            if ($order->warranty_claim_id) {
                $claim = WarrantyClaim::query()->findOrFail($order->warranty_claim_id);
                $this->warranties->returnFromWorkshop($claim, $operator);
            }
        });

        return $order->fresh(['warehouse', 'technician', 'parts']);
    }

    private function transition(ServiceOrder $order, string $to, User $operator): void
    {
        if (! $order->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "Transicion invalida de {$order->status} a {$to}.",
            ]);
        }

        $from = $order->status;
        $timestamps = [
            ServiceOrder::STATUS_DIAGNOSED => 'diagnosed_at',
            ServiceOrder::STATUS_DELIVERED => 'delivered_at',
            ServiceOrder::STATUS_CANCELLED => 'cancelled_at',
        ];

        $order->update([
            'status' => $to,
            $timestamps[$to] ?? 'updated_at' => now(),
        ]);

        $this->recordTransition($order, $to, $operator, $from);
    }

    private function recordTransition(ServiceOrder $order, string $to, User $operator, ?string $from = null): void
    {
        ServiceOrderStatusHistory::create([
            'service_order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $operator->id,
            'changed_at' => now(),
        ]);
    }

    private function recomputeTotals(ServiceOrder $order): void
    {
        $parts = $order->parts()->get();

        $partsBase = $parts->sum(fn ($p) => (float) $p->base_unit_price * (float) $p->quantity);
        $partsLocal = $parts->sum(fn ($p) => (float) $p->unit_price * (float) $p->quantity);

        $order->update([
            'parts_base_amount' => round($partsBase, 4),
            'parts_local_amount' => round($partsLocal, 4),
            'total_base_amount' => round((float) $order->labor_base_amount + $partsBase, 4),
            'total_local_amount' => round((float) $order->labor_local_amount + $partsLocal, 4),
        ]);
    }

    private function assertNotClosed(ServiceOrder $order): void
    {
        if (in_array($order->status, [ServiceOrder::STATUS_CLOSED, ServiceOrder::STATUS_CANCELLED], true)) {
            throw new RuntimeException('La orden de servicio esta cerrada o cancelada.');
        }
    }
}
