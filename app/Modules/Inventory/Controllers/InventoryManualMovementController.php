<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Inventory\Requests\InventoryManualMovementFilterRequest;
use App\Modules\Inventory\Requests\StoreInventoryManualMovementRequest;
use App\Modules\Inventory\Resources\InventoryManualMovementResource;
use App\Modules\Inventory\Services\AuthorizedInventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class InventoryManualMovementController extends Controller
{
    public function index(InventoryManualMovementFilterRequest $request): AnonymousResourceCollection
    {
        $query = InventoryManualMovement::query()
            ->with([
                'product',
                'productVariant',
                'warehouse',
                'creator',
                'approver',
                'rejector',
            ]);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('product_variant_id')) {
            $query->where('product_variant_id', $request->product_variant_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        return InventoryManualMovementResource::collection(
            $query
                ->latest()
                ->paginate(20)
        );
    }

    public function show(
        Request $request,
        InventoryManualMovement $movement
    ): InventoryManualMovementResource {
        abort_unless($request->user()?->can('inventory.manual_movements.view'), 403);

        return new InventoryManualMovementResource(
            $movement->load([
                'product',
                'warehouse',
                'creator',
                'approver',
                'rejector',
                'stockMovement',
            ])
        );
    }

    public function store(
        StoreInventoryManualMovementRequest $request
    ): InventoryManualMovementResource {

        $movement = InventoryManualMovement::create([
            'warehouse_id' => $request->warehouse_id,
            'product_id' => $request->product_id,
            'product_variant_id' => $request->product_variant_id,
            'quantity' => $request->quantity,
            'type' => $request->type,
            'reason' => $request->reason,
            'notes' => $request->notes,
            'status' => 'pending',
            'created_by' => $request->user()?->id,
        ]);

        return new InventoryManualMovementResource(
            $movement->load([
                'product',
                'warehouse',
                'creator',
            ])
        );
    }

    public function approve(
        Request $request,
        InventoryManualMovement $movement,
        AuthorizedInventoryMovementService $inventory
    ): InventoryManualMovementResource {
        abort_unless($request->user()?->can('inventory.manual_movements.approve'), 403);

        return DB::transaction(function () use ($request, $movement, $inventory): InventoryManualMovementResource {
            $movement = InventoryManualMovement::query()
                ->lockForUpdate()
                ->findOrFail($movement->id);

            abort_if(
                $movement->status !== 'pending',
                422,
                'El movimiento ya fue procesado.'
            );

            $warehouse = Warehouse::query()->findOrFail($movement->warehouse_id);
            $product = Product::query()->findOrFail($movement->product_id);
            $referenceType = InventoryManualMovement::class;
            $referenceId = $movement->id;

            $stockMovement = match ($movement->type) {
                'adjustment_in', 'return_internal', 'found' => $inventory->manualIn(
                    $request->user(),
                    $warehouse,
                    $product,
                    $movement->quantity,
                    $movement->reason,
                    $movement->product_variant_id,
                    $referenceType,
                    $referenceId,
                ),
                'damaged', 'loss', 'write_off' => $inventory->manualDamaged(
                    $request->user(),
                    $warehouse,
                    $product,
                    $movement->quantity,
                    $movement->reason,
                    $movement->product_variant_id,
                    $referenceType,
                    $referenceId,
                ),
                default => $inventory->manualOut(
                    $request->user(),
                    $warehouse,
                    $product,
                    $movement->quantity,
                    $movement->reason,
                    $movement->product_variant_id,
                    $referenceType,
                    $referenceId,
                ),
            };

            $movement->update([
                'status' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'stock_movement_id' => $stockMovement->id,
            ]);

            return new InventoryManualMovementResource(
                $movement->load([
                    'product',
                    'warehouse',
                    'creator',
                    'approver',
                    'stockMovement',
                ])
            );
        });
    }

    public function reject(
        Request $request,
        InventoryManualMovement $movement
    ): InventoryManualMovementResource {
        abort_unless($request->user()?->can('inventory.manual_movements.cancel'), 403);

        return DB::transaction(function () use ($request, $movement): InventoryManualMovementResource {
            $movement = InventoryManualMovement::query()
                ->lockForUpdate()
                ->findOrFail($movement->id);

            abort_if(
                $movement->status !== 'pending',
                422,
                'El movimiento ya fue procesado.'
            );

            $movement->update([
                'status' => 'rejected',
                'rejected_by' => $request->user()?->id,
                'rejected_at' => now(),
                'rejection_reason' => $request->input('reason'),
            ]);

            return new InventoryManualMovementResource(
                $movement->load([
                    'product',
                    'warehouse',
                    'creator',
                    'rejector',
                ])
            );
        });
    }
}
