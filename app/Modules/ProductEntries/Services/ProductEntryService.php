<?php

namespace App\Modules\ProductEntries\Services;

use App\Models\User;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductEntries\Models\ProductEntryItem;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductEntryService
{
    public function __construct(private readonly InventoryMovementService $inventory)
    {
    }

    public function create(User $user, array $data): ProductEntry
    {
        return DB::transaction(function () use ($user, $data): ProductEntry {
            $this->validateItems($data['items']);

            $sequence = $this->nextSequence();
            $entry = ProductEntry::create([
                'sequence' => $sequence,
                'document_number' => 'ENT-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'reason' => $data['reason'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => ProductEntry::STATUS_PROCESSED,
                'created_by' => $user->id,
                'processed_at' => $data['processed_at'] ?? now(),
            ]);

            foreach ($data['items'] as $item) {
                $warehouse = Warehouse::query()->findOrFail($item['warehouse_id']);
                $product = Product::query()->findOrFail($item['product_id']);
                $quantity = (float) $item['quantity'];
                $unitCost = isset($item['unit_cost']) ? (float) $item['unit_cost'] : null;

                $movement = $this->inventory->purchase(
                    warehouse: $warehouse,
                    product: $product,
                    quantity: $quantity,
                    unitCost: $unitCost,
                    createdBy: $user,
                    reason: "Entrada {$entry->document_number}: {$entry->reason}",
                    referenceType: ProductEntry::class,
                    referenceId: $entry->id,
                );

                ProductEntryItem::create([
                    'product_entry_id' => $entry->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'stock_movement_id' => $movement->id,
                    'serial_units' => $item['serial_units'] ?? null,
                ]);

                $this->createProductUnits($product, $warehouse, $movement->id, $item['serial_units'] ?? []);
            }

            return $entry->refresh()->load(['items.product', 'items.warehouse']);
        });
    }

    private function validateItems(array $items): void
    {
        $serialKeys = [];

        foreach ($items as $index => $item) {
            $product = Product::query()->findOrFail($item['product_id']);
            $serialUnits = $item['serial_units'] ?? [];
            $quantity = (float) $item['quantity'];

            if ($product->requiresSerializedTracking()) {
                if ($quantity !== floor($quantity)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Los productos serializados requieren cantidad entera.',
                    ]);
                }

                if (count($serialUnits) !== (int) $quantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.serial_units" => 'Debe indicar un IMEI o serial por cada unidad del producto.',
                    ]);
                }
            } elseif ($serialUnits !== []) {
                throw ValidationException::withMessages([
                    "items.{$index}.serial_units" => 'Solo los productos serializados pueden recibir IMEIs o seriales.',
                ]);
            }

            foreach ($serialUnits as $serialIndex => $serialUnit) {
                $key = "{$serialUnit['serial_type']}:{$serialUnit['serial_number']}";

                if (isset($serialKeys[$key])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.serial_units.{$serialIndex}.serial_number" => 'No se pueden repetir IMEIs o seriales dentro de la misma entrada.',
                    ]);
                }

                $serialKeys[$key] = true;

                if (ProductUnit::query()
                    ->where('serial_type', $serialUnit['serial_type'])
                    ->where('serial_number', $serialUnit['serial_number'])
                    ->exists()) {
                    throw ValidationException::withMessages([
                        "items.{$index}.serial_units.{$serialIndex}.serial_number" => "El serial {$serialUnit['serial_number']} ya existe en la empresa actual.",
                    ]);
                }
            }
        }
    }

    private function createProductUnits(Product $product, Warehouse $warehouse, int $movementId, array $serialUnits): void
    {
        if (! $product->requiresSerializedTracking()) {
            return;
        }

        foreach ($serialUnits as $serialUnit) {
            ProductUnit::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'serial_type' => $serialUnit['serial_type'],
                'serial_number' => $serialUnit['serial_number'],
                'status' => ProductUnit::STATUS_AVAILABLE,
                'acquired_stock_movement_id' => $movementId,
            ]);
        }
    }

    private function nextSequence(): int
    {
        $lastSequence = ProductEntry::query()
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->value('sequence');

        return ((int) $lastSequence) + 1;
    }
}
