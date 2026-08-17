<?php

namespace App\Modules\POS\Services;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\POS\Models\PosOrder;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

class PosReservationExpiryService
{
    public function __construct(private readonly InventoryMovementService $inventory) {}

    public function expire(Tenant $tenant, int $limit = 100): int
    {
        $expiredOrders = PosOrder::query()
            ->where('status', PosOrder::STATUS_OPEN)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);
        $expired = 0;

        foreach ($expiredOrders as $order) {
            $released = DB::transaction(function () use ($order): bool {
                $lockedOrder = PosOrder::query()
                    ->lockForUpdate()
                    ->find($order->id);

                if (! $lockedOrder || $lockedOrder->status !== PosOrder::STATUS_OPEN || $lockedOrder->reserved_until === null || $lockedOrder->reserved_until->isFuture()) {
                    return false;
                }

                $lockedOrder->load(['sale.items.product', 'sale.items.variant', 'sale.items.warehouse']);
                $hasReservation = StockMovement::query()
                    ->where('type', 'reserved')
                    ->where('reference_type', PosOrder::class)
                    ->where('reference_id', $lockedOrder->id)
                    ->exists();

                if ($hasReservation) {
                    foreach ($lockedOrder->sale->items as $item) {
                        $this->inventory->release(
                            warehouse: $item->warehouse,
                            product: $item->product,
                            quantity: (float) $item->quantity,
                            createdBy: null,
                            reason: "Liberacion automatica reserva POS #{$lockedOrder->id}",
                            referenceType: PosOrder::class,
                            referenceId: $lockedOrder->id,
                            productVariantId: $item->product_variant_id,
                        );
                        $this->releaseSerializedUnits($item->product_unit_ids ?? []);
                    }
                }

                $lockedOrder->update(['reserved_until' => null]);

                return $hasReservation;
            });

            $expired += $released ? 1 : 0;
        }

        return $expired;
    }

    private function releaseSerializedUnits(array $unitIds): void
    {
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
}
