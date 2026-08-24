<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

class InventoryReservationExpirationService
{
    public const EXPIRATION_REFERENCE_TYPE = 'inventory_reservation_expired';

    public function __construct(
        private readonly InventoryMovementService $inventory,
        private readonly TenantManager $tenantManager,
        private readonly SyncCatalogOutboxService $syncCatalog,
    ) {}

    public function expireTenant(Tenant $tenant, int $limit = 500): int
    {
        $this->tenantManager->set($tenant);
        $expired = 0;
        $reservations = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'reserved')
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($reservations as $reservation) {
            $expired += $this->expireReservation($tenant, $reservation) ? 1 : 0;
        }

        return $expired;
    }

    private function expireReservation(Tenant $tenant, StockMovement $reservation): bool
    {
        return DB::transaction(function () use ($tenant, $reservation): bool {
            $reservation = StockMovement::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->type !== 'reserved'
                || $reservation->reservation_expires_at === null
                || $reservation->reservation_expires_at->isFuture()
                || $this->hasReleaseForReservation($reservation)) {
                return false;
            }

            $warehouse = Warehouse::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereKey($reservation->warehouse_id)
                ->firstOrFail();
            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereKey($reservation->product_id)
                ->firstOrFail();
            $released = $this->inventory->release(
                warehouse: $warehouse,
                product: $product,
                quantity: (float) $reservation->quantity,
                reason: 'Reserva vencida',
                referenceType: self::EXPIRATION_REFERENCE_TYPE,
                referenceId: $reservation->id,
                productVariantId: $reservation->product_variant_id,
            );
            $this->syncCatalog->stockMovementCreated($released);

            ProductUnit::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('released_stock_movement_id', $reservation->id)
                ->where('status', ProductUnit::STATUS_RESERVED)
                ->lockForUpdate()
                ->get()
                ->each(function (ProductUnit $unit): void {
                    $unit->update([
                        'status' => ProductUnit::STATUS_AVAILABLE,
                        'released_stock_movement_id' => null,
                    ]);
                    $this->syncCatalog->productUnitUpdated($unit->refresh());
                });

            return true;
        });
    }

    private function hasReleaseForReservation(StockMovement $reservation): bool
    {
        $hasExpirationRelease = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $reservation->tenant_id)
            ->where('type', 'released')
            ->where('reference_type', self::EXPIRATION_REFERENCE_TYPE)
            ->where('reference_id', $reservation->id)
            ->where('warehouse_id', $reservation->warehouse_id)
            ->where('product_id', $reservation->product_id)
            ->when(
                $reservation->product_variant_id === null,
                fn ($query) => $query->whereNull('product_variant_id'),
                fn ($query) => $query->where('product_variant_id', $reservation->product_variant_id),
            )
            ->exists();

        if ($hasExpirationRelease) {
            return true;
        }

        $releasedQuantity = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $reservation->tenant_id)
            ->where('type', 'released')
            ->where('reference_type', $reservation->reference_type)
            ->where('reference_id', $reservation->reference_id)
            ->where('warehouse_id', $reservation->warehouse_id)
            ->where('product_id', $reservation->product_id)
            ->when(
                $reservation->product_variant_id === null,
                fn ($query) => $query->whereNull('product_variant_id'),
                fn ($query) => $query->where('product_variant_id', $reservation->product_variant_id),
            )
            ->sum('quantity');
        $releasedForPreviousReservations = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $reservation->tenant_id)
            ->where('type', 'reserved')
            ->where('id', '<', $reservation->id)
            ->where('reference_type', $reservation->reference_type)
            ->where('reference_id', $reservation->reference_id)
            ->where('warehouse_id', $reservation->warehouse_id)
            ->where('product_id', $reservation->product_id)
            ->when(
                $reservation->product_variant_id === null,
                fn ($query) => $query->whereNull('product_variant_id'),
                fn ($query) => $query->where('product_variant_id', $reservation->product_variant_id),
            )
            ->sum('quantity');

        return max(0.0, (float) $releasedQuantity - (float) $releasedForPreviousReservations)
            >= (float) $reservation->quantity;
    }
}
