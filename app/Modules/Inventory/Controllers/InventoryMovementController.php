<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Requests\InventoryMovementRequest;
use App\Modules\Inventory\Requests\InventoryTransferRequest;
use App\Modules\Inventory\Resources\StockMovementResource;
use App\Modules\Inventory\Services\AuthorizedInventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryMovementController extends Controller
{
    public function __construct(private readonly AuthorizedInventoryMovementService $inventory) {}

    public function purchase(InventoryMovementRequest $request): StockMovementResource
    {
        $movement = $this->inventory->purchase(
            $request->user(),
            $this->warehouse($request->integer('warehouse_id')),
            $this->product($request->integer('product_id')),
            (float) $request->input('quantity'),
            $request->filled('unit_cost') ? (float) $request->input('unit_cost') : null,
            $request->input('reason'),
        );

        return new StockMovementResource($movement);
    }

    public function sale(InventoryMovementRequest $request): StockMovementResource
    {
        $movement = $this->inventory->sale(
            $request->user(),
            $this->warehouse($request->integer('warehouse_id')),
            $this->product($request->integer('product_id')),
            (float) $request->input('quantity'),
            $request->input('reason'),
        );

        return new StockMovementResource($movement);
    }

    public function adjustmentIn(InventoryMovementRequest $request): StockMovementResource
    {
        $movement = $this->inventory->adjustmentIn(
            $request->user(),
            $this->warehouse($request->integer('warehouse_id')),
            $this->product($request->integer('product_id')),
            (float) $request->input('quantity'),
            $request->input('reason'),
        );

        return new StockMovementResource($movement);
    }

    public function adjustmentOut(InventoryMovementRequest $request): StockMovementResource
    {
        $movement = $this->inventory->adjustmentOut(
            $request->user(),
            $this->warehouse($request->integer('warehouse_id')),
            $this->product($request->integer('product_id')),
            (float) $request->input('quantity'),
            $request->input('reason'),
        );

        return new StockMovementResource($movement);
    }

    public function reserve(InventoryMovementRequest $request): StockMovementResource
    {
        $movement = $this->inventory->reserve(
            $request->user(),
            $this->warehouse($request->integer('warehouse_id')),
            $this->product($request->integer('product_id')),
            (float) $request->input('quantity'),
            $request->input('reason'),
        );

        return new StockMovementResource($movement);
    }

    public function release(InventoryMovementRequest $request): StockMovementResource
    {
        $movement = $this->inventory->release(
            $request->user(),
            $this->warehouse($request->integer('warehouse_id')),
            $this->product($request->integer('product_id')),
            (float) $request->input('quantity'),
            $request->input('reason'),
        );

        return new StockMovementResource($movement);
    }

    public function damage(InventoryMovementRequest $request): StockMovementResource
    {
        $movement = $this->inventory->markDamaged(
            $request->user(),
            $this->warehouse($request->integer('warehouse_id')),
            $this->product($request->integer('product_id')),
            (float) $request->input('quantity'),
            $request->input('reason'),
        );

        return new StockMovementResource($movement);
    }

    public function transfer(InventoryTransferRequest $request): AnonymousResourceCollection
    {
        $movements = $this->inventory->transfer(
            $request->user(),
            $this->warehouse($request->integer('from_warehouse_id')),
            $this->warehouse($request->integer('to_warehouse_id')),
            $this->product($request->integer('product_id')),
            (float) $request->input('quantity'),
            $request->input('reason'),
        );

        return StockMovementResource::collection(collect($movements));
    }

    private function warehouse(int $id): Warehouse
    {
        return Warehouse::query()->findOrFail($id);
    }

    private function product(int $id): Product
    {
        return Product::query()->findOrFail($id);
    }
}
