<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Flujo de conteo fisico (cycle count) con captura y ajuste automatico.
 *
 *  1. Crear el conteo (status=draft)
 *  2. snapshot() copia el stock_balances actual a stock_count_items (status=pending)
 *  3. start() pasa a status=capturing
 *  4. capture() registra el counted_quantity por item (status=counted)
 *  5. complete() genera StockMovement de tipo adjustment_in/out por la diferencia
 *     (status=adjusted) y marca el conteo como completed
 */
class StockCountService
{
    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly InventoryMovementService $inventory,
    ) {}

    public function create(Tenant $tenant, Warehouse $warehouse, array $data, ?int $userId): StockCount
    {
        return StockCount::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'count_type' => $data['count_type'] ?? StockCount::TYPE_FULL,
            'status' => StockCount::STATUS_DRAFT,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'created_by' => $userId,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Copia el stock actual a los items del conteo (uno por par warehouse/product/location).
     */
    public function snapshot(StockCount $count): int
    {
        if ($count->items()->exists()) {
            return 0;
        }

        $warehouseId = $count->warehouse_id;

        $balances = DB::table('stock_balances')
            ->where('tenant_id', $count->tenant_id)
            ->where('warehouse_id', $warehouseId)
            ->select(['product_id', 'location_id', 'quantity_available'])
            ->get();

        $now = now();
        $rows = $balances->map(fn ($b) => [
            'tenant_id' => $count->tenant_id,
            'stock_count_id' => $count->id,
            'product_id' => $b->product_id,
            'location_id' => $b->location_id,
            'system_quantity' => $b->quantity_available,
            'status' => StockCountItem::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows === []) {
            return 0;
        }

        DB::table('stock_count_items')->insert($rows);

        return count($rows);
    }

    public function start(StockCount $count): StockCount
    {
        if ($count->status !== StockCount::STATUS_DRAFT) {
            throw new \RuntimeException("Solo se puede iniciar un conteo en status 'draft'.");
        }

        $count->update([
            'status' => StockCount::STATUS_CAPTURING,
            'started_at' => now(),
        ]);

        return $count->refresh();
    }

    /**
     * Registra los contados. $captures es ['item_id' => counted_quantity, ...]
     */
    public function capture(StockCount $count, array $captures, ?int $userId): int
    {
        if ($count->status !== StockCount::STATUS_CAPTURING) {
            throw new \RuntimeException("Solo se pueden capturar items en status 'capturing'.");
        }

        $counted = 0;
        foreach ($captures as $itemId => $countedQuantity) {
            $item = StockCountItem::query()
                ->where('tenant_id', $count->tenant_id)
                ->where('stock_count_id', $count->id)
                ->whereKey($itemId)
                ->firstOrFail();

            $item->update([
                'counted_quantity' => (float) $countedQuantity,
                'variance' => (float) $countedQuantity - (float) $item->system_quantity,
                'status' => StockCountItem::STATUS_COUNTED,
                'counted_at' => now(),
                'counted_by' => $userId,
                'notes' => $item->notes,
            ]);
            $counted++;
        }

        return $counted;
    }

    /**
     * Completa el conteo: genera StockMovement de adjustment_in/out por la varianza
     * de cada item contado, y marca los items como adjusted.
     */
    public function complete(StockCount $count, ?int $approverId): array
    {
        $adjustments = ['in' => 0, 'out' => 0, 'skipped' => 0];

        DB::transaction(function () use ($count, $approverId, &$adjustments) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== StockCount::STATUS_CAPTURING) {
                throw new \RuntimeException("Solo se puede completar un conteo en status 'capturing'.");
            }

            $items = $count->items()->lockForUpdate()->get();
            if ($items->contains(fn (StockCountItem $item) => $item->status !== StockCountItem::STATUS_COUNTED)) {
                throw new \RuntimeException('Todos los items deben estar contados antes de completar el conteo.');
            }

            foreach ($items as $item) {
                $variance = (float) $item->variance;
                if (abs($variance) < 0.0001) {
                    $adjustments['skipped']++;
                    $item->update(['status' => StockCountItem::STATUS_ADJUSTED]);

                    continue;
                }

                $type = $variance > 0 ? 'adjustment_in' : 'adjustment_out';
                $quantity = abs($variance);

                $warehouse = Warehouse::query()->findOrFail($count->warehouse_id);
                $product = Product::query()->findOrFail($item->product_id);

                if ($type === 'adjustment_in') {
                    $this->inventory->adjustmentIn(
                        warehouse: $warehouse,
                        product: $product,
                        quantity: $quantity,
                        createdBy: $approverId === null ? null : User::find($approverId),
                        reason: "Cycle count {$count->code}",
                        referenceType: 'stock_count',
                        referenceId: $count->id,
                    );
                } else {
                    $this->inventory->adjustmentOut(
                        warehouse: $warehouse,
                        product: $product,
                        quantity: $quantity,
                        createdBy: $approverId === null ? null : User::find($approverId),
                        reason: "Cycle count {$count->code}",
                        referenceType: 'stock_count',
                        referenceId: $count->id,
                    );
                }

                $variance > 0 ? $adjustments['in']++ : $adjustments['out']++;
                $item->update(['status' => StockCountItem::STATUS_ADJUSTED]);
            }

            $count->update([
                'status' => StockCount::STATUS_COMPLETED,
                'completed_at' => now(),
                'approved_by' => $approverId,
            ]);
        });

        return $adjustments;
    }

    public function cancel(StockCount $count, ?int $userId): StockCount
    {
        if ($count->status === StockCount::STATUS_COMPLETED) {
            throw new \RuntimeException('No se puede cancelar un conteo completado.');
        }

        $count->update(['status' => StockCount::STATUS_CANCELLED]);

        return $count->refresh();
    }
}
