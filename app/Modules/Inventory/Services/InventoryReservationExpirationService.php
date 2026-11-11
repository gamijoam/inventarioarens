<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class InventoryReservationExpirationService
{
    public const EXPIRATION_REFERENCE_TYPE = InventoryMovementService::EXPIRED_RESERVATION_REFERENCE_TYPE;

    /**
     * Documentos de vida larga: su reserva se libera al despachar o cancelar,
     * no por tiempo.
     */
    private const NON_EXPIRING_REFERENCE_TYPES = [
        InventoryTransfer::class,
    ];

    /** @var array<int, string> */
    private array $errors = [];

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
            ->where(fn ($query) => $query
                ->whereNull('reference_type')
                ->orWhereNotIn('reference_type', self::NON_EXPIRING_REFERENCE_TYPES))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($reservations as $reservation) {
            try {
                $expired += $this->expireReservation($tenant, $reservation) ? 1 : 0;
            } catch (Throwable $exception) {
                // Una reserva inconsistente no detiene las demas ni otros tenants.
                $this->errors[] = "Tenant {$tenant->slug}, reserva #{$reservation->id}: {$exception->getMessage()}";
                Log::warning('No se pudo vencer la reserva de inventario.', [
                    'tenant_id' => $tenant->id,
                    'stock_movement_id' => $reservation->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $expired;
    }

    /**
     * @return array<int, string>
     */
    public function pullErrors(): array
    {
        $errors = $this->errors;
        $this->errors = [];

        return $errors;
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
                || $reservation->reservation_expires_at->isFuture()) {
                return false;
            }

            $openQuantity = $this->inventory->openQuantityForReservation($reservation);
            if ($openQuantity <= 0.0001) {
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
                quantity: $openQuantity,
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
}
