<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class AuthorizedInventoryMovementService
{
    public function __construct(private readonly InventoryMovementService $inventory) {}

    /**
     * @throws AuthorizationException
     */
    public function purchase(User $user, Warehouse $warehouse, Product $product, float $quantity, ?float $unitCost = null, ?string $reason = null): StockMovement
    {
        Gate::forUser($user)->authorize('inventory.receive-operation', [$warehouse, $product]);

        return $this->inventory->purchase($warehouse, $product, $quantity, $unitCost, $user, $reason);
    }

    /**
     * @throws AuthorizationException
     */
    public function sale(User $user, Warehouse $warehouse, Product $product, float $quantity, ?string $reason = null): StockMovement
    {
        Gate::forUser($user)->authorize('inventory.sale-operation', [$warehouse, $product]);

        return $this->inventory->sale($warehouse, $product, $quantity, $user, $reason);
    }

    /**
     * @throws AuthorizationException
     */
    public function adjustmentIn(User $user, Warehouse $warehouse, Product $product, float $quantity, ?string $reason = null): StockMovement
    {
        Gate::forUser($user)->authorize('inventory.adjust-operation', [$warehouse, $product]);

        return $this->inventory->adjustmentIn($warehouse, $product, $quantity, $user, $reason);
    }

    /**
     * @throws AuthorizationException
     */
    public function adjustmentOut(User $user, Warehouse $warehouse, Product $product, float $quantity, ?string $reason = null): StockMovement
    {
        Gate::forUser($user)->authorize('inventory.adjust-operation', [$warehouse, $product]);

        return $this->inventory->adjustmentOut($warehouse, $product, $quantity, $user, $reason);
    }

    /**
     * @throws AuthorizationException
     */
    public function reserve(User $user, Warehouse $warehouse, Product $product, float $quantity, ?string $reason = null): StockMovement
    {
        Gate::forUser($user)->authorize('inventory.adjust-operation', [$warehouse, $product]);

        return $this->inventory->reserve($warehouse, $product, $quantity, $user, $reason);
    }

    /**
     * @throws AuthorizationException
     */
    public function release(User $user, Warehouse $warehouse, Product $product, float $quantity, ?string $reason = null): StockMovement
    {
        Gate::forUser($user)->authorize('inventory.adjust-operation', [$warehouse, $product]);

        return $this->inventory->release($warehouse, $product, $quantity, $user, $reason);
    }

    /**
     * @throws AuthorizationException
     */
    public function markDamaged(User $user, Warehouse $warehouse, Product $product, float $quantity, ?string $reason = null): StockMovement
    {
        Gate::forUser($user)->authorize('inventory.adjust-operation', [$warehouse, $product]);

        return $this->inventory->markDamaged($warehouse, $product, $quantity, $user, $reason);
    }

    /**
     * @return array{0: StockMovement, 1: StockMovement}
     *
     * @throws AuthorizationException
     */
    public function transfer(User $user, Warehouse $fromWarehouse, Warehouse $toWarehouse, Product $product, float $quantity, ?string $reason = null): array
    {
        Gate::forUser($user)->authorize('inventory.transfer-operation', [$fromWarehouse, $toWarehouse, $product]);

        return $this->inventory->transfer($fromWarehouse, $toWarehouse, $product, $quantity, $user, $reason);
    }
}
