<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryManualMovement;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Requests\InventoryManualMovementFilterRequest;
use App\Modules\Inventory\Requests\StoreInventoryManualMovementRequest;
use App\Modules\Inventory\Resources\InventoryManualMovementResource;
use App\Modules\Inventory\Services\AuthorizedInventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Time\BusinessDateRange;
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
            $query->where('created_at', '>=', BusinessDateRange::startOfDay($request->string('from')->toString()));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', BusinessDateRange::endOfDay($request->string('to')->toString()));
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
            'serial_units' => $request->input('serial_units'),
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

        abort_if(
            $movement->status !== 'pending',
            422,
            'El movimiento ya fue procesado.'
        );

        $warehouse = Warehouse::query()
            ->findOrFail($movement->warehouse_id);

        $product = Product::query()
            ->findOrFail($movement->product_id);

        $stockMovement = DB::transaction(function () use ($request, $movement, $inventory, $warehouse, $product) {
            $sm = match ($movement->type) {
                'adjustment_in',
                'return_internal',
                'found' => $inventory->manualIn(
                    $request->user(),
                    $warehouse,
                    $product,
                    $movement->quantity,
                    $movement->reason,
                    $movement->product_variant_id,
                ),

                'damaged',
                'loss',
                'write_off' => $inventory->manualDamaged(
                    $request->user(),
                    $warehouse,
                    $product,
                    $movement->quantity,
                    $movement->reason,
                    $movement->product_variant_id,
                ),

                default => $inventory->manualOut(
                    $request->user(),
                    $warehouse,
                    $product,
                    $movement->quantity,
                    $movement->reason,
                    $movement->product_variant_id,
                ),
            };

            // Manejo de unidades serializadas (IMEIs)
            if ($product->requiresSerializedTracking() && ! empty($movement->serial_units)) {
                $isInput = in_array($movement->type, ['adjustment_in', 'return_internal', 'found'], true);

                if ($isInput) {
                    // ENTRADA: Crear o reactivar registros de unidades físicas disponibles
                    foreach ($movement->serial_units as $unitData) {
                        $serialNumber = trim((string) $unitData['serial_number']);
                        $serialType = $unitData['serial_type'] ?? ProductUnit::SERIAL_TYPE_IMEI;

                        ProductUnit::updateOrCreate(
                            [
                                'serial_type' => $serialType,
                                'serial_number' => $serialNumber,
                            ],
                            [
                                'product_id' => $product->id,
                                'product_variant_id' => $movement->product_variant_id,
                                'warehouse_id' => $warehouse->id,
                                'status' => ProductUnit::STATUS_AVAILABLE,
                                'acquired_stock_movement_id' => $sm->id,
                                'released_stock_movement_id' => null,
                            ]
                        );
                    }
                } else {
                    // SALIDA: Dar de baja o marcar como dañados los IMEIs seleccionados
                    $serialNumbers = array_map(
                        fn ($u) => trim((string) ($u['serial_number'] ?? '')),
                        $movement->serial_units
                    );

                    $targetStatus = in_array($movement->type, ['damaged', 'loss', 'write_off'], true)
                        ? ProductUnit::STATUS_DAMAGED
                        : ProductUnit::STATUS_REMOVED;

                    ProductUnit::query()
                        ->where('product_id', $product->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->whereIn('serial_number', $serialNumbers)
                        ->where('status', ProductUnit::STATUS_AVAILABLE)
                        ->update([
                            'status' => $targetStatus,
                            'released_stock_movement_id' => $sm->id,
                        ]);
                }
            }

            $movement->update([
                'status' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'stock_movement_id' => $sm->id,
            ]);

            return $sm;
        });

        return new InventoryManualMovementResource(
            $movement->load([
                'product',
                'warehouse',
                'creator',
                'approver',
                'stockMovement',
            ])
        );
    }

    public function reject(
        Request $request,
        InventoryManualMovement $movement
    ): InventoryManualMovementResource {
        abort_unless($request->user()?->can('inventory.manual_movements.cancel'), 403);

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
    }
}
