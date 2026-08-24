<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Exceptions\CrossTenantInventoryReferenceException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        ?int $productVariantId = null,
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
            productVariantId: $productVariantId,
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
        ?float $unitCost = null,
        ?int $productVariantId = null,
    ): StockMovement {
        return $this->decreaseAvailable(
            type: 'sale',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: $unitCost,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
            productVariantId: $productVariantId,
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

    public function adjustmentIn(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?int $productVariantId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockMovement {
        return $this->increaseAvailable('adjustment_in', $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId, $productVariantId);
    }

    public function saleReturn(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?float $unitCost = null,
    ): StockMovement {
        return $this->increaseAvailable(
            type: 'sale_return',
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

    public function saleReversal(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?int $referenceId = null,
        ?float $unitCost = null,
        ?int $productVariantId = null,
    ): StockMovement {
        return $this->increaseAvailable(
            type: 'sale_reversal',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: $unitCost,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: 'sale_reversal',
            referenceId: $referenceId,
            productVariantId: $productVariantId,
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
        ?float $unitCost = null,
    ): StockMovement {
        return $this->increaseDamaged(
            type: 'sale_return',
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

    public function adjustmentOut(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $productVariantId = null,
    ): StockMovement {
        return $this->decreaseAvailable(
            type: 'adjustment_out',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
            productVariantId: $productVariantId,
        );
    }

    /**
     * Salida de stock por consumo de piezas en una orden de servicio (Taller).
     * Igual que adjustmentOut pero registra el unit_cost de la pieza y permite
     * referenciar la ServiceOrder para trazabilidad.
     */
    public function serviceExit(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?float $unitCost = null,
        ?int $productVariantId = null,
    ): StockMovement {
        return $this->decreaseAvailable(
            type: 'adjustment_out',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: $unitCost,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
            productVariantId: $productVariantId,
        );
    }

    /**
     * Salida de stock por aceptar una solicitud de transferencia inter-empresa
     * (la empresa origen pierde stock que envia a su empresa hermana).
     * Tipo dedicado 'transfer_request_out' para que el kardex distinga este
     * caso de un adjustment_out normal.
     */
    public function transferRequestOut(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $productVariantId = null,
    ): StockMovement {
        return $this->decreaseAvailable(
            type: 'transfer_request_out',
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            createdBy: $createdBy,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
            productVariantId: $productVariantId,
        );
    }

    /**
     * Entrada de stock por aceptar una solicitud de transferencia inter-empresa
     * (la empresa destino recibe stock de su empresa hermana).
     * Tipo dedicado 'transfer_request_in' para que el kardex distinga este
     * caso de una compra normal.
     */
    public function transferRequestIn(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $productVariantId = null,
    ): StockMovement {
        return $this->increaseAvailable(
            'transfer_request_in',
            $warehouse,
            $product,
            $quantity,
            null,
            $createdBy,
            $reason,
            $referenceType,
            $referenceId,
            $productVariantId,
        );
    }

    public function reserve(
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $productVariantId = null,
    ): StockMovement {
        return DB::transaction(function () use ($warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId, $productVariantId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product, $productVariantId);
            $this->ensureEnough((float) $balance->quantity_available, $quantity, 'available');

            $balance->quantity_available = (float) $balance->quantity_available - $quantity;
            $balance->quantity_reserved = (float) $balance->quantity_reserved + $quantity;
            $balance->save();

            return $this->recordMovement('reserved', $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId, $productVariantId);
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
        ?int $productVariantId = null,
    ): StockMovement {
        return DB::transaction(function () use ($warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId, $productVariantId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product, $productVariantId);
            $this->ensureEnough((float) $balance->quantity_reserved, $quantity, 'reserved');

            $balance->quantity_reserved = (float) $balance->quantity_reserved - $quantity;
            $balance->quantity_available = (float) $balance->quantity_available + $quantity;
            $balance->save();

            return $this->recordMovement('released', $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId, $productVariantId);
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
        ?int $productVariantId = null,
    ): StockMovement {
        return DB::transaction(function () use ($warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId, $productVariantId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product, $productVariantId);
            $this->ensureEnough((float) $balance->quantity_reserved, $quantity, 'reserved');

            $balance->quantity_reserved = (float) $balance->quantity_reserved - $quantity;
            $balance->save();

            return $this->recordMovement('transfer_out', $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId, $productVariantId);
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
        ?int $productVariantId = null,
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
            productVariantId: $productVariantId,
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
        ?int $productVariantId = null,
    ): StockMovement {
        return DB::transaction(function () use ($warehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId, $productVariantId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product, $productVariantId);
            $this->ensureEnough((float) $balance->quantity_available, $quantity, 'available');

            $balance->quantity_available = (float) $balance->quantity_available - $quantity;
            $balance->quantity_damaged = (float) $balance->quantity_damaged + $quantity;
            $balance->save();

            return $this->recordMovement('damaged', $warehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId, $productVariantId);
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
        ?int $productVariantId = null,
    ): array {
        return DB::transaction(function () use ($fromWarehouse, $toWarehouse, $product, $quantity, $createdBy, $reason, $referenceType, $referenceId, $productVariantId): array {
            $this->validateOperation($fromWarehouse, $product, $quantity);
            $this->assertSameTenant($toWarehouse);

            $fromBalance = $this->balanceFor($fromWarehouse, $product, $productVariantId);
            $this->ensureEnough((float) $fromBalance->quantity_available, $quantity, 'available');

            $toBalance = $this->balanceFor($toWarehouse, $product, $productVariantId);

            $fromBalance->quantity_available = (float) $fromBalance->quantity_available - $quantity;
            $fromBalance->save();

            $toBalance->quantity_available = (float) $toBalance->quantity_available + $quantity;
            $toBalance->save();

            return [
                $this->recordMovement('transfer_out', $fromWarehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId, $productVariantId),
                $this->recordMovement('transfer_in', $toWarehouse, $product, $quantity, null, $createdBy, $reason, $referenceType, $referenceId, $productVariantId),
            ];
        });
    }

    /**
     * Valida unidades serializadas antes de mover stock.
     * La consulta bloquea las filas para que otra operacion no pueda tomar
     * el mismo IMEI mientras la transaccion actual termina.
     *
     * @param  array<int, string>  $unitIds
     * @param  array<int, string>  $allowedStatuses
     * @return array<int, ProductUnit>
     */
    public function validateSerializedUnits(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        array $unitIds,
        array $allowedStatuses = [ProductUnit::STATUS_AVAILABLE],
    ): array {
        $this->validateOperation($warehouse, $product, $quantity);

        if (! $product->requiresSerializedTracking()) {
            if ($unitIds !== []) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Solo los productos serializados pueden usar unidades especificas.',
                ]);
            }

            return [];
        }

        if ($quantity !== floor($quantity)) {
            throw ValidationException::withMessages([
                'quantity' => 'Los productos serializados requieren cantidad entera.',
            ]);
        }

        $normalizedIds = array_values(array_map('intval', $unitIds));
        if (count($normalizedIds) !== (int) $quantity) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Debe indicar una unidad serializada por cada cantidad del movimiento.',
            ]);
        }

        if (count($normalizedIds) !== count(array_unique($normalizedIds))) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'No se puede repetir la misma unidad serializada.',
            ]);
        }

        $units = ProductUnit::query()
            ->whereIn('id', $normalizedIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($units->count() !== count($normalizedIds)) {
            throw ValidationException::withMessages([
                'product_unit_ids' => 'Una o mas unidades serializadas no existen.',
            ]);
        }

        foreach ($normalizedIds as $unitId) {
            $unit = $units->get($unitId);
            if ((int) $unit->product_id !== (int) $product->id
                || (int) $unit->warehouse_id !== (int) $warehouse->id
                || ! in_array($unit->status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'product_unit_ids' => 'Una o mas unidades serializadas no estan disponibles en el almacen origen.',
                ]);
            }
        }

        return array_map(fn (int $unitId): ProductUnit => $units->get($unitId), $normalizedIds);
    }

    /**
     * Resuelve IMEIs/seriales existentes y disponibles a sus IDs. Nunca crea
     * unidades: los seriales deben entrar primero por compras o recepciones.
     *
     * @param  array<int, array{serial_type: string, serial_number: string}>  $serialUnits
     * @return array<int, int>
     */
    public function resolveAvailableSerializedUnits(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        array $serialUnits,
    ): array {
        if (! $product->requiresSerializedTracking()) {
            if ($serialUnits !== []) {
                throw ValidationException::withMessages([
                    'serial_units' => 'Solo los productos serializados pueden usar IMEIs o seriales.',
                ]);
            }

            return [];
        }

        $normalized = array_map(fn (array $unit): array => [
            'serial_type' => trim((string) ($unit['serial_type'] ?? '')),
            'serial_number' => trim((string) ($unit['serial_number'] ?? '')),
        ], $serialUnits);

        if (count($normalized) !== (int) $quantity || $quantity !== floor($quantity)) {
            throw ValidationException::withMessages([
                'serial_units' => 'Debe indicar un IMEI o serial disponible por cada cantidad.',
            ]);
        }

        foreach ($normalized as $index => $unit) {
            if (! in_array($unit['serial_type'], [ProductUnit::SERIAL_TYPE_IMEI, ProductUnit::SERIAL_TYPE_SERIAL], true)
                || $unit['serial_number'] === '') {
                throw ValidationException::withMessages([
                    "serial_units.{$index}" => 'Cada unidad debe tener un tipo y numero serial validos.',
                ]);
            }
        }

        $keys = array_map(fn (array $unit): string => $unit['serial_type'].'|'.$unit['serial_number'], $normalized);
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages([
                'serial_units' => 'No se puede repetir el mismo IMEI o serial.',
            ]);
        }

        $units = ProductUnit::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('status', ProductUnit::STATUS_AVAILABLE)
            ->whereIn('serial_number', array_column($normalized, 'serial_number'))
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ProductUnit $unit): string => $unit->serial_type.'|'.$unit->serial_number);

        $ids = [];
        foreach ($keys as $index => $key) {
            $unit = $units->get($key);
            if (! $unit) {
                throw ValidationException::withMessages([
                    "serial_units.{$index}" => "El IMEI o serial {$normalized[$index]['serial_number']} no esta disponible en el almacen origen.",
                ]);
            }
            $ids[] = $unit->id;
        }

        $this->validateSerializedUnits($product, $warehouse, $quantity, $ids);

        return $ids;
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
        ?int $productVariantId = null,
    ): StockMovement {
        return DB::transaction(function () use ($type, $warehouse, $product, $quantity, $unitCost, $createdBy, $reason, $referenceType, $referenceId, $productVariantId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product, $productVariantId);
            $balance->quantity_available = (float) $balance->quantity_available + $quantity;
            $balance->save();

            return $this->recordMovement($type, $warehouse, $product, $quantity, $unitCost, $createdBy, $reason, $referenceType, $referenceId, $productVariantId);
        });
    }

    private function increaseDamaged(
        string $type,
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?float $unitCost = null,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $productVariantId = null,
    ): StockMovement {
        return DB::transaction(function () use ($type, $warehouse, $product, $quantity, $unitCost, $createdBy, $reason, $referenceType, $referenceId, $productVariantId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product, $productVariantId);
            $balance->quantity_damaged = (float) $balance->quantity_damaged + $quantity;
            $balance->save();

            return $this->recordMovement($type, $warehouse, $product, $quantity, $unitCost, $createdBy, $reason, $referenceType, $referenceId, $productVariantId);
        });
    }

    private function decreaseAvailable(
        string $type,
        Warehouse $warehouse,
        Product $product,
        float $quantity,
        ?float $unitCost = null,
        ?User $createdBy = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $productVariantId = null,
    ): StockMovement {
        return DB::transaction(function () use ($type, $warehouse, $product, $quantity, $unitCost, $createdBy, $reason, $referenceType, $referenceId, $productVariantId): StockMovement {
            $this->validateOperation($warehouse, $product, $quantity);

            $balance = $this->balanceFor($warehouse, $product, $productVariantId);
            $this->ensureEnough((float) $balance->quantity_available, $quantity, 'available');

            $balance->quantity_available = (float) $balance->quantity_available - $quantity;
            $balance->save();

            return $this->recordMovement($type, $warehouse, $product, $quantity, $unitCost, $createdBy, $reason, $referenceType, $referenceId, $productVariantId);
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
        ?int $productVariantId = null,
    ): StockMovement {
        $movement = StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $productVariantId,
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

    private function balanceFor(Warehouse $warehouse, Product $product, ?int $productVariantId = null): StockBalance
    {
        $query = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id);

        if ($productVariantId === null) {
            $query->whereNull('product_variant_id');
        } else {
            $query->where('product_variant_id', $productVariantId);
        }

        $balance = $query->lockForUpdate()->first();

        if ($balance) {
            return $balance;
        }

        return StockBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $productVariantId,
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
