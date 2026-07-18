<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Exceptions\CrossTenantInventoryReferenceException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

class InventoryMovementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function purchase(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?float $unitCost = null,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return $this->increaseAvailable(
            type: 'purchase',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: $unitCost,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    public function sale(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return $this->decreaseAvailable(
            type: 'sale',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    public function purchaseReturn(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return $this->decreaseAvailable(
            type: 'purchase_return',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    public function adjustmentIn(Warehouse $warehouse, Product $product, float $quantity, ?User $createdBy = null, ?string $reason = null): StockMovement
    {
        return $this->increaseAvailable('adjustment_in', $warehouse, $product, $quantity, null, $createdBy, $reason);
    }

    public function saleReturn(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return $this->increaseAvailable(
            type: 'sale_return',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: null,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    public function damagedSaleReturn(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return $this->increaseDamaged(
            type: 'sale_return',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    public function adjustmentOut(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return $this->decreaseAvailable('adjustment_out', $warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId);
    }

    public function reserve(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return DB::transaction(function () use ($warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product);
            $this->ensureEnough((float) $balance->quantity_available, $quantity, 'available');

            $balance->quantity_available = (float) $balance->quantity_available - $quantity;
            $balance->quantity_reserved = (float) $balance->quantity_reserved + $quantity;
            $balance->save();

            return $this->recordMovement('reserved', $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId);
        });
    }

    public function release(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return DB::transaction(function () use ($warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product);
            $this->ensureEnough((float) $balance->quantity_reserved, $quantity, 'reserved');

            $balance->quantity_reserved = (float) $balance->quantity_reserved - $quantity;
            $balance->quantity_available = (float) $balance->quantity_available + $quantity;
            $balance->save();

            return $this->recordMovement('released', $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId);
        });
    }

    public function dispatchReservedTransfer(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return DB::transaction(function () use ($warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product);
            $this->ensureEnough((float) $balance->quantity_reserved, $quantity, 'reserved');

            $balance->quantity_reserved = (float) $balance->quantity_reserved - $quantity;
            $balance->save();

            return $this->recordMovement('transfer_out', $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId);
        });
    }

    public function receiveTransfer(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return $this->increaseAvailable(
            type: 'transfer_in',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: null,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    public function markDamaged(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return DB::transaction(function () use ($warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product);
            $this->ensureEnough((float) $balance->quantity_available, $quantity, 'available');

            $balance->quantity_available = (float) $balance->quantity_available - $quantity;
            $balance->quantity_damaged = (float) $balance->quantity_damaged + $quantity;
            $balance->save();

            return $this->recordMovement('damaged', $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId);
        });
    }

    public function transfer(
        Warehouse $fromWarehouse,
        Warehouse $toWarehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): array {
        return DB::transaction(function () use ($fromWarehouse, $toWarehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId): array {
            $this->validateOperation($fromWarehouse, $product, $quantity);
            $this->assertSameTenant($toWarehouse);

            $fromBalance = $this->balanceFor($fromWarehouse, $product);
            $this->ensureEnough((float) $fromBalance->quantity_available, $quantity, 'available');

            $toBalance = $this->balanceFor($toWarehouse, $product);

            $fromBalance->quantity_available = (float) $fromBalance->quantity_available - $quantity;
            $fromBalance->save();

            $toBalance->quantity_available = (float) $toBalance->quantity_available + $quantity;
            $toBalance->save();

            return [
                $this->recordMovement('transfer_out', $fromWarehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId),
                $this->recordMovement('transfer_in', $toWarehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId),
            ];
        });
    }

    private function increaseAvailable(
        string $type,
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?float $unitCost = null,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return DB::transaction(function () use ($type, $warehouse, $product, $quantity, $unitCost, $createdBy, $reason, $referenceType, $referenceId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product);
            $balance->quantity_available = (float) $balance->quantity_available + $quantity;
            $balance->save();

            return $this->recordMovement($type, $warehouse, $product, $quantity, $unitCost, $createdBy, $reason, $referenceType, $referenceId);
        });
    }

    private function increaseDamaged(
        string $type,
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return DB::transaction(function () use ($type, $warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product);
            $balance->quantity_damaged = (float) $balance->quantity_damaged + $quantity;
            $balance->save();

            return $this->recordMovement($type, $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId);
        });
    }

    private function decreaseAvailable(
        string $type,
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return DB::transaction(function () use ($type, $warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product);
            $this->ensureEnough((float) $balance->quantity_available, $quantity, 'available');

            $balance->quantity_available = (float) $balance->quantity_available - $quantity;
            $balance->save();

            return $this->recordMovement($type, $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId);
        });
    }

    private function recordMovement(
        string $type,
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?float $unitCost = null,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        $movement = StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy?->id,
        ]);

        $this->audit->record(
            action: 'inventory.movement.created',
            entity: $movement,
            user: $createdBy,
            newValues: [
                'warehouse_id' => $movement->warehouse_id,
                'product_id' => $movement->product_id,
                'type' => $movement->type,
                'quantity' => (float) $movement->quantity,
                'unit_cost' => $movement->unit_cost === null ? null : (float) $movement->unit_cost,
                'reason' => $movement->reason,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
            ],
        );

        return $movement;
    }

    private function balanceFor(Warehouse $warehouse, Product $product): StockBalance
    {
        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if ($balance) {
            return $balance;
        }

        return StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
        ]);
    }

    private function validateOperation(Warehouse $warehouse, Product $product, float $quantity): void
    {
        $this->assertPositiveQuantity($quantity);
        $this->assertSameTenant($warehouse);
        $this->assertSameTenant($product);
    }

    private function assertSameTenant(object $model): void
    {
        $tenantId = app(TenantManager::class)->require()->id;

        if ((int) $model->tenant_id !== (int) $tenantId) {
            throw new CrossTenantInventoryReferenceException;
        }
    }

    private function assertPositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidStockQuantityException;
        }
    }

    private function ensureEnough(float $available, float $required, string $bucket): void
    {
        if ($available < $required) {
            throw new InsufficientStockException($bucket);
        }
    }
}
