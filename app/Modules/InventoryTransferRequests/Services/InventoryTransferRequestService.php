<?php

namespace App\Modules\InventoryTransferRequests\Services;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\InventoryTransferRequests\Events\TransferRequestAccepted;
use App\Modules\InventoryTransferRequests\Events\TransferRequestCancelled;
use App\Modules\InventoryTransferRequests\Events\TransferRequestCreated;
use App\Modules\InventoryTransferRequests\Events\TransferRequestRejected;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequestItem;
use App\Modules\Products\Models\Product;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTransferRequestService
{
    public function __construct(
        private readonly InventoryMovementService $inventory,
        private readonly SyncCatalogOutboxService $syncCatalog,
    ) {}

    public function create(User $user, array $data): InventoryTransferRequest
    {
        $originTenant = app(TenantManager::class)->require();
        $destinationTenant = $this->resolveDestinationTenant($data, $originTenant);

        $request = DB::transaction(function () use ($user, $data, $originTenant, $destinationTenant): InventoryTransferRequest {
            $fromWarehouse = Warehouse::query()->findOrFail($data['from_warehouse_id']);
            $this->validateOriginItems($fromWarehouse, $data['items']);

            $sequence = $this->nextSequence($originTenant);
            $request = InventoryTransferRequest::create([
                'sequence' => $sequence,
                'document_number' => 'TREQ-'.$originTenant->id.'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'origin_tenant_id' => $originTenant->id,
                'destination_tenant_id' => $destinationTenant->id,
                'from_warehouse_id' => $fromWarehouse->id,
                'status' => InventoryTransferRequest::STATUS_REQUESTED,
                'reason' => $data['reason'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'requested_by' => $user->id,
                'requested_at' => now(),
            ]);

            foreach ($data['items'] as $item) {
                $unitIds = $item['product_unit_ids'] ?? [];
                // Prioridad: serial_units del frontend (forma {serial_type, serial_number}).
                // Fallback: serialSnapshot desde product_unit_ids (legacy).
                $serialUnits = $item['serial_units'] ?? null;
                if ($serialUnits === null && $unitIds !== []) {
                    $serialUnits = $this->serialSnapshot($unitIds);
                }
                $serialUnits ??= [];

                InventoryTransferRequestItem::create([
                    'inventory_transfer_request_id' => $request->id,
                    'origin_product_id' => $item['product_id'],
                    'quantity' => (float) $item['quantity'],
                    'product_unit_ids' => $unitIds ?: null,
                    'serial_units' => $serialUnits,
                ]);
            }

            return $request->refresh()->load(['originTenant', 'destinationTenant', 'fromWarehouse', 'items.originProduct']);
        });

        $this->syncCatalog->inventoryTransferRequestCreated($request);

        // Difundir el evento al tenant destino. Esto activa
        // el push in-app en el frontend del destinatario (< 1s).
        // Usamos `fromModel()` para no serializar el modelo entero: el
        // evento es primitivo para evitar que Reverb se caiga al
        // serializar relaciones eager-loaded.
        event(TransferRequestCreated::fromModel($request));

        return $request;
    }

    public function accept(InventoryTransferRequest $request, User $user, array $data): InventoryTransferRequest
    {
        $accepted = DB::transaction(function () use ($request, $user, $data): InventoryTransferRequest {
            $request = InventoryTransferRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();

            if ($request->status !== InventoryTransferRequest::STATUS_REQUESTED) {
                throw ValidationException::withMessages([
                    'status' => 'Solo una solicitud pendiente puede aceptarse.',
                ]);
            }

            $destinationWarehouse = Warehouse::query()->findOrFail($data['destination_warehouse_id']);
            $itemsById = collect($data['items'])->keyBy('request_item_id');

            if ($itemsById->count() !== $request->items->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Debe indicar producto destino para cada item solicitado.',
                ]);
            }

            foreach ($request->items as $item) {
                $acceptedItem = $itemsById->get($item->id);

                if (! $acceptedItem) {
                    throw ValidationException::withMessages([
                        'items' => 'Debe indicar producto destino para cada item solicitado.',
                    ]);
                }

                $this->processAcceptedItem(
                    $request,
                    $item,
                    $destinationWarehouse,
                    (int) $acceptedItem['destination_product_id'],
                    $user,
                    $acceptedItem['serial_units'] ?? null,
                );
            }

            $request->update([
                'destination_warehouse_id' => $destinationWarehouse->id,
                'status' => InventoryTransferRequest::STATUS_COMPLETED,
                'response_notes' => $data['response_notes'] ?? null,
                'responded_by' => $user->id,
                'responded_at' => now(),
                'completed_at' => now(),
            ]);

            return $request->refresh()->load([
                'originTenant',
                'destinationTenant',
                'fromWarehouse',
                'destinationWarehouse',
                'items.originProduct',
                'items.destinationProduct',
            ]);
        });

        $this->syncCatalog->inventoryTransferRequestAccepted($accepted);

        // Difundir el evento al tenant ORIGEN para notificar al admin que
        // su solicitud fue aceptada. `fromModel()` para no serializar
        // el modelo entero (Reverb se cae si lo intenta).
        event(TransferRequestAccepted::fromModel($accepted));

        return $accepted;
    }

    public function reject(InventoryTransferRequest $request, User $user, array $data): InventoryTransferRequest
    {
        $rejected = DB::transaction(function () use ($request, $user, $data): InventoryTransferRequest {
            $request = InventoryTransferRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== InventoryTransferRequest::STATUS_REQUESTED) {
                throw ValidationException::withMessages([
                    'status' => 'Solo una solicitud pendiente puede rechazarse.',
                ]);
            }

            $request->update([
                'status' => InventoryTransferRequest::STATUS_REJECTED,
                'response_notes' => $data['response_notes'] ?? null,
                'responded_by' => $user->id,
                'responded_at' => now(),
            ]);

            return $request->refresh()->load(['originTenant', 'destinationTenant', 'fromWarehouse', 'items.originProduct']);
        });

        $this->syncCatalog->inventoryTransferRequestRejected($rejected);

        // Difundir el evento al tenant ORIGEN para notificar al admin que
        // su solicitud fue rechazada (y el motivo si lo hay). `fromModel()`
        // para no serializar el modelo entero.
        event(TransferRequestRejected::fromModel($rejected));

        return $rejected;
    }

    public function cancel(InventoryTransferRequest $request, User $user): InventoryTransferRequest
    {
        $cancelled = DB::transaction(function () use ($request, $user): InventoryTransferRequest {
            $request = InventoryTransferRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== InventoryTransferRequest::STATUS_REQUESTED) {
                throw ValidationException::withMessages([
                    'status' => 'Solo una solicitud pendiente puede cancelarse.',
                ]);
            }

            $request->update([
                'status' => InventoryTransferRequest::STATUS_CANCELLED,
                'responded_by' => $user->id,
                'responded_at' => now(),
            ]);

            return $request->refresh()->load(['originTenant', 'destinationTenant', 'fromWarehouse', 'items.originProduct']);
        });

        $this->syncCatalog->inventoryTransferRequestCancelled($cancelled);

        // Difundir el evento al tenant DESTINO para notificar al admin
        // que la solicitud fue retirada. `fromModel()` para no
        // serializar el modelo entero.
        event(TransferRequestCancelled::fromModel($cancelled));

        return $cancelled;
    }

    private function processAcceptedItem(
        InventoryTransferRequest $request,
        InventoryTransferRequestItem $item,
        Warehouse $destinationWarehouse,
        int $destinationProductId,
        User $user,
        ?array $serialUnitsFromPayload = null,
    ): void {
        $tenantManager = app(TenantManager::class);
        $currentTenant = $tenantManager->current();
        $requesterTenant = Tenant::query()->findOrFail($request->origin_tenant_id);
        $respondingTenant = Tenant::query()->findOrFail($request->destination_tenant_id);

        $tenantManager->set($requesterTenant);
        $requesterProduct = Product::query()->findOrFail($item->origin_product_id);
        $requesterWarehouse = Warehouse::query()->findOrFail($request->from_warehouse_id);

        $tenantManager->set($respondingTenant);
        $respondingProduct = Product::query()->findOrFail($destinationProductId);

        if ($requesterProduct->requiresSerializedTracking() !== $respondingProduct->requiresSerializedTracking()) {
            $tenantManager->set($currentTenant);

            throw ValidationException::withMessages([
                'items' => 'El producto seleccionado debe tener el mismo tipo de control que el producto solicitado.',
            ]);
        }

        try {
            $tenantManager->set($respondingTenant);
            [$unitIds, $serialUnits] = $this->resolveRespondingUnits(
                $respondingProduct,
                $destinationWarehouse,
                (float) $item->quantity,
                $serialUnitsFromPayload,
            );
            $outMovement = $this->inventory->transferRequestOut(
                warehouse: $destinationWarehouse,
                product: $respondingProduct,
                quantity: (float) $item->quantity,
                createdBy: $user,
                reason: "Salida interempresa {$request->document_number}",
                referenceType: InventoryTransferRequest::class,
                referenceId: $request->id,
            );
            $this->removeRespondingUnits($unitIds, $outMovement->id);

            $tenantManager->set($requesterTenant);
            $inMovement = $this->inventory->transferRequestIn(
                warehouse: $requesterWarehouse,
                product: $requesterProduct,
                quantity: (float) $item->quantity,
                createdBy: $user,
                reason: "Entrada interempresa {$request->document_number}",
                referenceType: InventoryTransferRequest::class,
                referenceId: $request->id,
            );
            $this->createRequesterUnits($requesterProduct, $requesterWarehouse, $inMovement->id, $serialUnits);
        } catch (InsufficientStockException $exception) {
            throw ValidationException::withMessages([
                'items' => 'El almacen de salida no tiene stock suficiente para completar la solicitud.',
            ]);
        } catch (InvalidStockQuantityException $exception) {
            throw ValidationException::withMessages([
                'items' => $exception->getMessage(),
            ]);
        } finally {
            $tenantManager->set($currentTenant);
        }

        $item->update([
            'destination_product_id' => $respondingProduct->id,
            'product_unit_ids' => $unitIds ?: null,
            'serial_units' => $serialUnits,
            'out_stock_movement_id' => $outMovement->id,
            'in_stock_movement_id' => $inMovement->id,
        ]);
    }

    private function resolveRespondingUnits(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        ?array $serialUnits,
    ): array {
        if (! $product->requiresSerializedTracking()) {
            if (is_array($serialUnits) && $serialUnits !== []) {
                throw ValidationException::withMessages([
                    'items' => 'Solo los productos serializados pueden seleccionar IMEIs o seriales.',
                ]);
            }

            return [[], []];
        }

        $expected = (int) $quantity;
        $normalized = collect($serialUnits ?? [])->map(fn (array $unit): array => [
            'serial_type' => (string) ($unit['serial_type'] ?? ''),
            'serial_number' => trim((string) ($unit['serial_number'] ?? '')),
        ])->values()->all();

        if (count($normalized) !== $expected) {
            throw ValidationException::withMessages([
                'items' => "Debe seleccionar {$expected} IMEI(s) o serial(es) disponibles.",
            ]);
        }

        $keys = array_map(
            fn (array $unit): string => $unit['serial_type'].'|'.$unit['serial_number'],
            $normalized,
        );

        if (count(array_unique($keys)) !== $expected) {
            throw ValidationException::withMessages([
                'items' => 'No se puede seleccionar el mismo IMEI o serial mas de una vez.',
            ]);
        }

        $availableUnits = ProductUnit::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('status', ProductUnit::STATUS_AVAILABLE)
            ->whereIn('serial_number', array_column($normalized, 'serial_number'))
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ProductUnit $unit): string => $unit->serial_type.'|'.$unit->serial_number);

        $unitIds = [];
        foreach ($normalized as $unit) {
            $key = $unit['serial_type'].'|'.$unit['serial_number'];
            $availableUnit = $availableUnits->get($key);

            if (! $availableUnit) {
                throw ValidationException::withMessages([
                    'items' => "El IMEI o serial {$unit['serial_number']} no esta disponible en el almacen de salida seleccionado.",
                ]);
            }

            $unitIds[] = $availableUnit->id;
        }

        return [$unitIds, $normalized];
    }

    private function validateOriginItems(Warehouse $fromWarehouse, array $items): void
    {
        $selectedUnitIds = [];
        $selectedSerials = []; // serial_numbers ya usados en esta solicitud.

        foreach ($items as $index => $item) {
            $product = Product::query()->findOrFail($item['product_id']);
            $unitIds = $item['product_unit_ids'] ?? [];
            $serialUnits = $item['serial_units'] ?? [];
            $serialNumbers = array_map(
                fn ($u) => is_array($u) ? trim((string) ($u['serial_number'] ?? '')) : '',
                $serialUnits,
            );
            $serialNumbers = array_values(array_filter($serialNumbers, fn ($s) => $s !== ''));
            $quantity = (float) $item['quantity'];

            if ($product->requiresSerializedTracking()) {
                if ($quantity !== floor($quantity)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Los productos serializados requieren cantidad entera.',
                    ]);
                }
                // Ya NO exigimos IMEIs al crear. Los IMEIs/seriales especificos
                // los elegira la empresa DESTINO al aceptar (ella es quien tiene
                // el stock y decide que unidades envia). Si el solicitante
                // incluyo product_unit_ids o serial_units, los validamos; si no,
                // no bloqueamos.
            } elseif ($unitIds !== [] || $serialNumbers !== []) {
                throw ValidationException::withMessages([
                    "items.{$index}.serial_units" => 'Solo los productos serializados pueden enviar unidades especificas.',
                ]);
            }

            // Validar que cada product_unit_id sea unico y este disponible en el stock origen.
            foreach ($unitIds as $unitIndex => $unitId) {
                if (isset($selectedUnitIds[$unitId])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_unit_ids.{$unitIndex}" => 'No se puede repetir la misma unidad en una solicitud.',
                    ]);
                }

                $selectedUnitIds[$unitId] = true;
                $unit = ProductUnit::query()->lockForUpdate()->find($unitId);

                if (! $unit
                    || (int) $unit->product_id !== (int) $item['product_id']
                    || (int) $unit->warehouse_id !== (int) $fromWarehouse->id
                    || $unit->status !== ProductUnit::STATUS_AVAILABLE) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_unit_ids.{$unitIndex}" => 'La unidad serializada no esta disponible en el almacen origen indicado.',
                    ]);
                }
            }

            // Validar que cada serial_number del frontend sea unico en esta solicitud.
            foreach ($serialNumbers as $snIndex => $sn) {
                if (isset($selectedSerials[$sn])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.serial_units.{$snIndex}" => 'No se puede repetir el mismo IMEI/serial en una solicitud.',
                    ]);
                }
                $selectedSerials[$sn] = true;
            }
        }
    }

    private function resolveDestinationTenant(array $data, Tenant $originTenant): Tenant
    {
        if (! empty($data['destination_tenant_slug'])) {
            $tenant = Tenant::query()->where('slug', $data['destination_tenant_slug'])->first();
        } else {
            $user = User::query()->where('email', $data['destination_user_email'])->first();
            $tenants = $user?->tenants()->wherePivot('status', 'active')->get() ?? collect();

            if ($tenants->count() !== 1) {
                throw ValidationException::withMessages([
                    'destination_user_email' => 'El correo destino debe pertenecer a una unica empresa activa.',
                ]);
            }

            $tenant = $tenants->first();
        }

        if (! $tenant || (int) $tenant->id === (int) $originTenant->id) {
            throw ValidationException::withMessages([
                'destination_tenant_slug' => 'La empresa destino debe existir y ser distinta a la empresa origen.',
            ]);
        }

        return $tenant;
    }

    private function serialSnapshot(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->get()
            ->map(fn (ProductUnit $unit): array => [
                'serial_type' => $unit->serial_type,
                'serial_number' => $unit->serial_number,
            ])
            ->values()
            ->all();
    }

    private function removeRespondingUnits(array $unitIds, int $movementId): void
    {
        if ($unitIds === []) {
            return;
        }

        ProductUnit::query()
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get()
            ->each(function (ProductUnit $unit) use ($movementId): void {
                if ($unit->status !== ProductUnit::STATUS_AVAILABLE) {
                    throw ValidationException::withMessages([
                        'items' => 'Una unidad serializada ya no esta disponible en el almacen de salida seleccionado.',
                    ]);
                }

                $unit->update([
                    'status' => ProductUnit::STATUS_REMOVED,
                    'warehouse_id' => null,
                    'released_stock_movement_id' => $movementId,
                ]);
            });
    }

    private function createRequesterUnits(Product $product, Warehouse $warehouse, int $movementId, array $serialUnits): void
    {
        if (! $product->requiresSerializedTracking()) {
            return;
        }

        foreach ($serialUnits as $serialUnit) {
            if (ProductUnit::query()
                ->where('serial_type', $serialUnit['serial_type'])
                ->where('serial_number', $serialUnit['serial_number'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'items' => "El serial {$serialUnit['serial_number']} ya existe en la empresa solicitante.",
                ]);
            }

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

    private function nextSequence(Tenant $tenant): int
    {
        $lastSequence = InventoryTransferRequest::query()
            ->where('origin_tenant_id', $tenant->id)
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->value('sequence');

        return ((int) $lastSequence) + 1;
    }
}
