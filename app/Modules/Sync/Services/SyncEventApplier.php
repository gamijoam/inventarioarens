<?php

namespace App\Modules\Sync\Services;

use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductImageVariant;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Models\SalesReturnItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SyncEventApplier
{
    private const REPROCESSABLE_EVENT_TYPES = [
        'branch.updated',
        'branch.created',
        'warehouse.updated',
        'warehouse.created',
        'product.updated',
        'product.created',
        'customer.updated',
        'customer.created',
        'stock_movement.updated',
        'stock_movement.created',
        'product_unit.updated',
        'product_unit.created',
        'price_list.updated',
        'price_list.created',
        'product_price.updated',
        'product_price.created',
        'price.updated',
        'warranty_policy.updated',
        'warranty_policy.created',
        'supplier.updated',
        'supplier.created',
        'brand.updated',
        'brand.created',
        'brand.deleted',
        'category.updated',
        'category.created',
        'category.deleted',
        'tag.updated',
        'tag.created',
        'tag.deleted',
        'payment_method.updated',
        'payment_method.created',
        'exchange_rate_type.updated',
        'exchange_rate_type.created',
        'exchange_rate.updated',
        'exchange_rate.created',
        'payment_method.updated',
        'payment_method.created',
        'cash_register.updated',
        'cash_register.created',
        'inventory_transfer.updated',
        'inventory_transfer.created',
        'product_entry.created',
        'product_exit.created',
        'inventory_transfer_request.created',
        'inventory_transfer_request.accepted',
        'inventory_transfer_request.rejected',
        'inventory_transfer_request.cancelled',
        'purchase_order.created',
        'purchase_order.received',
        'pos.order.pending',
        'pos.order.payment_added',
        'pos.order.paid',
        'pos.order.cancelled',
        'accounts_receivable.payment_registered',
        'sales_return.updated',
        'product.image.uploaded',
        'product.image.updated',
        'product.image.deleted',
    ];

    public function applyPending(Tenant $tenant, int $limit = 50): array
    {
        $events = DB::table('sync_inbox')
            ->where('tenant_id', $tenant->id)
            ->where(function ($query): void {
                $query
                    ->where('status', 'received')
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', 'ignored')
                            ->whereIn('event_type', self::REPROCESSABLE_EVENT_TYPES);
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $this->applyEvents($tenant, $events);
    }

    public function applyEventUuids(Tenant $tenant, array $eventUuids): array
    {
        $eventUuids = array_values(array_filter(array_map(
            fn (mixed $eventUuid): string => (string) $eventUuid,
            $eventUuids
        )));

        if ($eventUuids === []) {
            return [
                'applied' => 0,
                'failed' => 0,
                'ignored' => 0,
            ];
        }

        $events = DB::table('sync_inbox')
            ->where('tenant_id', $tenant->id)
            ->whereIn('event_uuid', $eventUuids)
            ->where(function ($query): void {
                $query
                    ->where('status', 'received')
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', 'ignored')
                            ->whereIn('event_type', self::REPROCESSABLE_EVENT_TYPES);
                    });
            })
            ->orderBy('id')
            ->get();

        return $this->applyEvents($tenant, $events);
    }

    private function applyEvents(Tenant $tenant, iterable $events): array
    {
        $summary = [
            'applied' => 0,
            'failed' => 0,
            'ignored' => 0,
        ];

        foreach ($events as $event) {
            try {
                $result = DB::transaction(fn (): string => $this->applyOne($tenant, (array) $event));

                if ($result === 'ignored') {
                    $summary['ignored']++;
                } else {
                    $summary['applied']++;
                }
            } catch (\Throwable $exception) {
                DB::table('sync_inbox')
                    ->where('tenant_id', $tenant->id)
                    ->where('id', $event->id)
                    ->update([
                        'status' => 'failed',
                        'last_error' => $exception->getMessage(),
                        'updated_at' => now(),
                    ]);

                $summary['failed']++;
            }
        }

        return $summary;
    }

    public function applyOne(Tenant $tenant, array $event): string
    {
        $this->assertPayloadIntegrity($event);
        $payload = $this->decodePayload($event['payload'] ?? []);

        $tenantManager = app(TenantManager::class);
        $previousTenant = $tenantManager->current();
        $tenantManager->set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            $result = match ($event['event_type']) {
                'branch.updated', 'branch.created' => $this->applyBranch($tenant, $payload),
                'warehouse.updated', 'warehouse.created' => $this->applyWarehouse($tenant, $payload),
                'product.updated', 'product.created' => $this->applyProduct($tenant, $payload),
                'product_variant.created', 'product_variant.updated' => $this->applyProductVariant($tenant, $payload),
                'product_variant.deleted' => $this->applyProductVariantDeleted($tenant, $payload),
                'product.image.uploaded', 'product.image.updated' => $this->applyProductImage($tenant, $payload),
                'product.image.deleted' => $this->applyProductImageDeleted($tenant, $payload),
                'customer.updated', 'customer.created' => $this->applyCustomer($tenant, $payload),
                'stock_movement.updated', 'stock_movement.created' => $this->applyStockMovement($tenant, $payload),
                'product_unit.updated', 'product_unit.created' => $this->applyProductUnit($tenant, $payload),
                'price_list.updated', 'price_list.created' => $this->applyPriceList($tenant, $payload),
                'product_price.updated', 'product_price.created', 'price.updated' => $this->applyProductPrice($tenant, $payload),
                'warranty_policy.updated', 'warranty_policy.created' => $this->applyWarrantyPolicy($tenant, $payload),
                'supplier.updated', 'supplier.created' => $this->applySupplier($tenant, $payload),
                'brand.updated', 'brand.created', 'brand.deleted' => $this->applyBrand($tenant, $payload),
                'category.updated', 'category.created', 'category.deleted' => $this->applyCategory($tenant, $payload),
                'tag.updated', 'tag.created', 'tag.deleted' => $this->applyTag($tenant, $payload),
                'exchange_rate_type.updated', 'exchange_rate_type.created' => $this->applyExchangeRateType($tenant, $payload),
                'exchange_rate.updated', 'exchange_rate.created' => $this->applyExchangeRate($tenant, $payload),
                'payment_method.updated', 'payment_method.created' => $this->applyPaymentMethod($tenant, $payload),
                'product_entry.created' => $this->applyProductEntry($tenant, $payload),
                'product_exit.created' => $this->applyProductExit($tenant, $payload),
                'purchase_order.created' => $this->applyPurchaseOrderCreated($tenant, $payload),
                'purchase_order.received' => $this->applyPurchaseOrderReceived($tenant, $payload),
                'cash_register.updated', 'cash_register.created' => $this->applyCashRegister($tenant, $payload),
                'inventory_transfer.updated', 'inventory_transfer.created' => $this->applyInventoryTransfer($tenant, $payload),
                'inventory_transfer_request.created' => $this->applyInventoryTransferRequestCreated($tenant, $payload),
                'inventory_transfer_request.accepted' => $this->applyInventoryTransferRequestAccepted($tenant, $payload),
                'inventory_transfer_request.rejected' => $this->applyInventoryTransferRequestRejected($tenant, $payload),
                'inventory_transfer_request.cancelled' => $this->applyInventoryTransferRequestCancelled($tenant, $payload),
                'pos.order.pending', 'pos.order.payment_added', 'pos.order.paid', 'pos.order.cancelled' => $this->applyPosOrder($tenant, $payload, $event),
                'accounts_receivable.payment_registered' => $this->applyReceivablePayment($tenant, $payload, $event),
                'sales_return.updated' => $this->applySalesReturn($tenant, $payload, $event),
                default => 'ignored',
            };
        } finally {
            $tenantManager->set($previousTenant ?? $tenant);
            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId(($previousTenant ?? $tenant)->id);
            }
        }

        DB::table('sync_inbox')
            ->where('tenant_id', $tenant->id)
            ->where('id', $event['id'])
            ->update([
                'status' => $result === 'ignored' ? 'ignored' : 'applied',
                'applied_at' => $result === 'ignored' ? null : now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        return $result;
    }

    private function applyBranch(Tenant $tenant, array $payload): string
    {
        $code = mb_strtoupper($this->requiredString($payload, 'code'));

        $this->upsertByKeys(
            'branches',
            ['tenant_id' => $tenant->id, 'code' => $code],
            [
                'name' => $this->requiredString($payload, 'name'),
                'status' => $payload['status'] ?? 'active',
                'updated_at' => now(),
            ]
        );

        return 'applied';
    }

    private function applyWarehouse(Tenant $tenant, array $payload): string
    {
        $code = mb_strtoupper($this->requiredString($payload, 'code'));
        $branch = $this->branchByCode($tenant, $this->requiredString($payload, 'branch_code'));

        $this->upsertByKeys(
            'warehouses',
            ['tenant_id' => $tenant->id, 'code' => $code],
            [
                'branch_id' => $branch->id,
                'name' => $this->requiredString($payload, 'name'),
                'status' => $payload['status'] ?? 'active',
                'updated_at' => now(),
            ]
        );

        return 'applied';
    }

    private function applyProduct(Tenant $tenant, array $payload): string
    {
        $sku = $this->requiredString($payload, 'sku');
        $now = now();

        $fields = [
            'name' => $this->requiredString($payload, 'name'),
            'sku' => $sku,
            'barcode' => $payload['barcode'] ?? null,
            'description' => $payload['description'] ?? null,
            'long_description' => $payload['long_description'] ?? null,
            'tracking_type' => $payload['tracking_type'] ?? 'quantity',
            'unit_of_measure' => $payload['unit_of_measure'] ?? 'unit',
            'track_stock' => array_key_exists('track_stock', $payload) ? (bool) $payload['track_stock'] : true,
            'base_price' => $payload['base_price'] ?? null,
            'profit_margin' => $payload['profit_margin'] ?? null,
            // Events generated before pricing_mode existed must preserve the
            // existing sale price instead of silently switching to automatic.
            'pricing_mode' => $payload['pricing_mode'] ?? Product::PRICING_MANUAL,
            'sale_currency' => strtoupper($payload['sale_currency'] ?? 'USD'),
            'sale_exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $payload['sale_exchange_rate_type_code'] ?? null, $payload['sale_exchange_rate_type_id'] ?? null),
            'warranty_policy_id' => $this->warrantyPolicyId($tenant, $payload),
            'image_url' => $payload['image_url'] ?? null,
            'min_stock' => $payload['min_stock'] ?? null,
            'max_stock' => $payload['max_stock'] ?? null,
            'reorder_quantity' => $payload['reorder_quantity'] ?? null,
            'is_catalog_active' => array_key_exists('is_catalog_active', $payload) ? (bool) $payload['is_catalog_active'] : true,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'updated_at' => $now,
        ];

        $product = DB::table('products')->where('tenant_id', $tenant->id)->where('sku', $sku)->first();
        $before = $product ? (array) $product : [];

        if ($product) {
            DB::table('products')->where('tenant_id', $tenant->id)->where('id', $product->id)->update($fields);
            $productId = (int) $product->id;
        } else {
            $productId = (int) DB::table('products')->insertGetId(array_merge($fields, [
                'tenant_id' => $tenant->id,
                'created_at' => $now,
            ]));
        }

        $after = (array) DB::table('products')->where('tenant_id', $tenant->id)->where('id', $productId)->first();
        $this->syncProductCatalogRelations($tenant, $productId, $payload);
        $this->recordProductAudit($productId, $before, $after);

        return 'applied';
    }

    private function syncProductCatalogRelations(Tenant $tenant, int $productId, array $payload): void
    {
        if (array_key_exists('brand_slug', $payload)) {
            $brandId = $payload['brand_slug']
                ? DB::table('brands')->where('tenant_id', $tenant->id)->where('slug', $payload['brand_slug'])->value('id')
                : null;
            DB::table('products')->where('tenant_id', $tenant->id)->where('id', $productId)->update(['brand_id' => $brandId]);
        }

        if (array_key_exists('category_slugs', $payload)) {
            DB::table('product_category')->where('tenant_id', $tenant->id)->where('product_id', $productId)->delete();
            foreach ((array) $payload['category_slugs'] as $slug) {
                $categoryId = DB::table('categories')->where('tenant_id', $tenant->id)->where('slug', $slug)->value('id');
                if ($categoryId) {
                    DB::table('product_category')->insert([
                        'tenant_id' => $tenant->id,
                        'product_id' => $productId,
                        'category_id' => $categoryId,
                    ]);
                }
            }
        }

        if (array_key_exists('tag_slugs', $payload)) {
            DB::table('product_tag')->where('tenant_id', $tenant->id)->where('product_id', $productId)->delete();
            foreach ((array) $payload['tag_slugs'] as $slug) {
                $tagId = DB::table('tags')->where('tenant_id', $tenant->id)->where('slug', $slug)->value('id');
                if ($tagId) {
                    DB::table('product_tag')->insert([
                        'tenant_id' => $tenant->id,
                        'product_id' => $productId,
                        'tag_id' => $tagId,
                    ]);
                }
            }
        }
    }

    private function applyCustomer(Tenant $tenant, array $payload): string
    {
        $documentType = mb_strtoupper($this->requiredString($payload, 'document_type'));
        $documentNumber = $this->requiredString($payload, 'document_number');
        $now = now();

        $this->upsertByKeys(
            'customers',
            [
                'tenant_id' => $tenant->id,
                'document_type' => $documentType,
                'document_number' => $documentNumber,
            ],
            [
                'name' => $this->requiredString($payload, 'name'),
                'phone' => $this->nullableString($payload['phone'] ?? null),
                'email' => $this->nullableLowerString($payload['email'] ?? null),
                'fiscal_address' => $payload['fiscal_address'] ?? null,
                'is_generic' => array_key_exists('is_generic', $payload) ? (bool) $payload['is_generic'] : false,
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                'updated_at' => $now,
            ]
        );

        return 'applied';
    }

    private function applyStockMovement(Tenant $tenant, array $payload): string
    {
        $product = $this->productBySku($tenant, $this->requiredString($payload, 'sku'));
        $warehouse = $this->warehouseByCode($tenant, $this->requiredString($payload, 'warehouse_code'));
        $sourceId = (int) ($payload['source_id'] ?? $payload['id'] ?? 0);
        $now = now();
        $createdAt = isset($payload['created_at']) ? Carbon::parse($payload['created_at']) : $now;

        $keys = $sourceId > 0
            ? ['tenant_id' => $tenant->id, 'reference_type' => 'sync_snapshot', 'reference_id' => $sourceId]
            : [
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'type' => $payload['type'] ?? 'adjustment',
                'reason' => $payload['reason'] ?? 'Snapshot de sincronizacion',
            ];

        $this->upsertByKeys(
            'stock_movements',
            $keys,
            [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'type' => $payload['type'] ?? 'adjustment',
                'quantity' => $payload['quantity'] ?? 0,
                'unit_cost' => $payload['unit_cost'] ?? null,
                'reason' => $payload['reason'] ?? 'Snapshot de sincronizacion',
                'reference_type' => 'sync_snapshot',
                'reference_id' => $sourceId > 0 ? $sourceId : null,
                'created_by' => null,
                'created_at' => $createdAt,
                'updated_at' => $now,
            ]
        );

        return 'applied';
    }

    /**
     * Aplica un product_entry (entrada manual de stock) del local a la nube.
     * Reproduce el flujo de InventoryMovementService::adjustmentIn + increaseAvailable:
     * crea/upsert la entrada y sus items, actualiza stock_balances.quantity_available
     * e inserta el stock_movements row. Idempotente via (tenant_id, document_number):
     * si el entry ya existe con el mismo document_number, no hace nada (no duplica items,
     * no suma stock, no crea movement). Esto garantiza que re-procesar el mismo evento
     * (p. ej. en REPROCESSABLE_EVENT_TYPES) no duplique el efecto.
     */
    private function applyProductEntry(Tenant $tenant, array $payload): string
    {
        $documentNumber = $this->requiredString($payload, 'document_number');

        $existingEntry = DB::table('product_entries')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', $documentNumber)
            ->first();

        if ($existingEntry) {
            return 'applied';
        }

        $sourceId = (int) ($payload['source_id'] ?? $payload['id'] ?? 0);
        $now = now();
        $processedAt = isset($payload['processed_at']) ? Carbon::parse($payload['processed_at']) : $now;

        return DB::transaction(function () use (
            $tenant, $documentNumber, $sourceId, $now, $processedAt, $payload
        ): string {
            $entryId = $this->upsertAndGetId(
                'product_entries',
                [
                    'tenant_id' => $tenant->id,
                    'document_number' => $documentNumber,
                ],
                [
                    'sequence' => $sourceId > 0 ? $sourceId : ((int) DB::table('product_entries')
                        ->where('tenant_id', $tenant->id)->max('sequence')) + 1,
                    'reason' => $payload['reason'] ?? null,
                    'reference' => $payload['reference'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'status' => $payload['status'] ?? 'processed',
                    'processed_at' => $processedAt,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $items = $payload['items'] ?? [];
            foreach ($items as $item) {
                $this->applyProductStockMovement(
                    tenant: $tenant,
                    documentType: 'entry',
                    productEntryId: $entryId,
                    productExitId: 0,
                    productSku: $this->requiredString($item, 'sku'),
                    warehouseCode: $this->requiredString($item, 'warehouse_code'),
                    quantity: (float) ($item['quantity'] ?? 0),
                    unitCost: $item['unit_cost'] ?? null,
                    serialUnits: $item['serial_units'] ?? null,
                    reason: "Entrada manual {$documentNumber}",
                    now: $now,
                );
            }

            return 'applied';
        });
    }

    /**
     * Aplica un `purchase_order.created` (estado `draft`) en la nube.
     * Como el PO no afecta stock todavia, solo guardamos metadata minima
     * para que la UI de la nube pueda mostrar la existencia de la orden
     * sin replicar todo el modelo de PurchaseOrder local-operational.
     * El efecto real sobre stock lo aplica `applyPurchaseOrderReceived`.
     *
     * Idempotente: si el (tenant, document_number) ya existe en la nube,
     * no hace nada.
     */
    private function applyPurchaseOrderCreated(Tenant $tenant, array $payload): string
    {
        $documentNumber = $this->requiredString($payload, 'document_number');

        $existing = DB::table('purchase_orders')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', $documentNumber)
            ->first();

        if ($existing) {
            return 'applied';
        }

        $now = now();
        $issuedAt = isset($payload['issued_at']) ? Carbon::parse($payload['issued_at']) : $now->toDateString();
        $dueDate = isset($payload['due_date']) ? Carbon::parse($payload['due_date']) : null;

        DB::table('purchase_orders')->insert([
            'tenant_id' => $tenant->id,
            'supplier_id' => null, // suppliers no se replican en esta iteracion
            'status' => $payload['status'] ?? 'draft',
            'document_number' => $documentNumber,
            'issued_at' => $issuedAt,
            'due_date' => $dueDate,
            'purchase_currency' => $payload['purchase_currency'] ?? 'USD',
            'exchange_rate_type_id' => $payload['exchange_rate_type_id'] ?? null,
            'exchange_rate' => $payload['exchange_rate'] ?? null,
            'total_base_amount' => (float) ($payload['total_base_amount'] ?? 0),
            'total_local_amount' => (float) ($payload['total_local_amount'] ?? 0),
            'received_base_amount' => 0,
            'received_local_amount' => 0,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 'applied';
    }

    /**
     * Aplica un `purchase_order.received` en la nube. Convierte la orden de
     * compra local en una entrada de stock (`product_entries` + items +
     * stock_movements) manteniendo el `document_number` original del PO.
     * Esto preserva la trazabilidad de la fuente (la compra) y mantiene
     * el stock sincronizado entre local y nube.
     *
     * Si la nube ya recibio este mismo `purchase_order.received` (por reintento
     * o reprocesamiento), `applyProductEntry` es idempotente via
     * (tenant_id, document_number) y no duplica stock.
     */
    private function applyPurchaseOrderReceived(Tenant $tenant, array $payload): string
    {
        $documentNumber = $this->requiredString($payload, 'document_number');
        $supplierName = $payload['supplier_name'] ?? null;

        // Mapeamos al shape que espera applyProductEntry.
        $mapped = [
            'document_number' => $documentNumber,
            'reason' => 'Compra a proveedor '.($supplierName ?? ''),
            'reference' => $documentNumber,
            'notes' => $supplierName ? "Proveedor: {$supplierName} | Doc compra: {$documentNumber}" : null,
            'status' => 'processed',
            'processed_at' => $payload['received_at'] ?? now()->toISOString(),
            'items' => $payload['items'] ?? [],
        ];

        return $this->applyProductEntry($tenant, $mapped);
    }

    /**
     * Aplica un product_exit (salida manual de stock) del local a la nube.
     * Reproduce InventoryMovementService::adjustmentOut + decreaseAvailable.
     * Decrementa stock_balances.quantity_available. Idempotente via (tenant_id, document_number):
     * si el exit ya existe con el mismo document_number, no hace nada.
     */
    private function applyProductExit(Tenant $tenant, array $payload): string
    {
        $documentNumber = $this->requiredString($payload, 'document_number');

        $existingExit = DB::table('product_exits')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', $documentNumber)
            ->first();

        if ($existingExit) {
            return 'applied';
        }

        $sourceId = (int) ($payload['source_id'] ?? $payload['id'] ?? 0);
        $now = now();
        $processedAt = isset($payload['processed_at']) ? Carbon::parse($payload['processed_at']) : $now;

        return DB::transaction(function () use (
            $tenant, $documentNumber, $sourceId, $now, $processedAt, $payload
        ): string {
            $exitId = $this->upsertAndGetId(
                'product_exits',
                [
                    'tenant_id' => $tenant->id,
                    'document_number' => $documentNumber,
                ],
                [
                    'sequence' => $sourceId > 0 ? $sourceId : ((int) DB::table('product_exits')
                        ->where('tenant_id', $tenant->id)->max('sequence')) + 1,
                    'reason' => $payload['reason'] ?? null,
                    'reference' => $payload['reference'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'status' => $payload['status'] ?? 'processed',
                    'processed_at' => $processedAt,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $items = $payload['items'] ?? [];
            foreach ($items as $item) {
                $this->applyProductStockMovement(
                    tenant: $tenant,
                    documentType: 'exit',
                    productEntryId: 0,
                    productExitId: $exitId,
                    productSku: $this->requiredString($item, 'sku'),
                    warehouseCode: $this->requiredString($item, 'warehouse_code'),
                    quantity: (float) ($item['quantity'] ?? 0),
                    unitCost: null,
                    serialUnits: $item['product_unit_ids'] ?? null,
                    reason: "Salida manual {$documentNumber}",
                    now: $now,
                );
            }

            return 'applied';
        });
    }

    /**
     * Helper compartido: actualiza stock_balances.quantity_available segun el signo del
     * documentType ('entry' suma, 'exit' resta), inserta el item de la entrada/salida y
     * registra el stock_movements row. Replica el flujo de InventoryMovementService
     * pero acoplado directamente a DB::transaction porque ese servicio asume
     * TenantManager::require() y el handler corre dentro del match de applyOne.
     */
    private function applyProductStockMovement(
        Tenant $tenant,
        string $documentType,
        int $productEntryId,
        int $productExitId,
        string $productSku,
        string $warehouseCode,
        float $quantity,
        ?string $unitCost,
        mixed $serialUnits,
        string $reason,
        $now,
    ): void {
        if ($quantity <= 0.0) {
            return;
        }

        $product = $this->productBySku($tenant, $productSku);
        $warehouse = $this->warehouseByCode($tenant, $warehouseCode);
        $normalizedSerialUnits = $this->normalizeSerialUnits($serialUnits);

        $stockBalance = DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if ($stockBalance) {
            $newQuantity = (float) $stockBalance->quantity_available + ($documentType === 'entry' ? $quantity : -$quantity);
            DB::table('stock_balances')
                ->where('tenant_id', $tenant->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->update([
                    'quantity_available' => $newQuantity,
                ]);
        } else {
            DB::table('stock_balances')->insert([
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity_available' => $documentType === 'entry' ? $quantity : -$quantity,
                'quantity_reserved' => 0,
                'quantity_damaged' => 0,
            ]);
        }

        $movementId = (int) DB::table('stock_movements')->insertGetId([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => $documentType,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reason' => $reason,
            'reference_type' => $documentType === 'entry' ? 'product_entry' : 'product_exit',
            'reference_id' => $documentType === 'entry' ? $productEntryId : $productExitId,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($documentType === 'entry') {
            DB::table('product_entry_items')->insert([
                'tenant_id' => $tenant->id,
                'product_entry_id' => $productEntryId,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'stock_movement_id' => $movementId,
                'serial_units' => $normalizedSerialUnits !== [] ? json_encode($normalizedSerialUnits) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->upsertProductUnitsFromEntry(
                tenant: $tenant,
                productId: (int) $product->id,
                warehouseId: (int) $warehouse->id,
                movementId: $movementId,
                serialUnits: $normalizedSerialUnits,
                now: $now,
            );
        } else {
            DB::table('product_exit_items')->insert([
                'tenant_id' => $tenant->id,
                'product_exit_id' => $productExitId,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'stock_movement_id' => $movementId,
                'product_unit_ids' => $normalizedSerialUnits !== [] ? json_encode($normalizedSerialUnits) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function normalizeSerialUnits(mixed $serialUnits): array
    {
        if (is_string($serialUnits)) {
            $decoded = json_decode($serialUnits, true);
            $serialUnits = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($serialUnits)) {
            return [];
        }

        return array_values(array_filter($serialUnits, fn ($serialUnit): bool => is_array($serialUnit)
            && isset($serialUnit['serial_type'], $serialUnit['serial_number'])
            && trim((string) $serialUnit['serial_type']) !== ''
            && trim((string) $serialUnit['serial_number']) !== ''));
    }

    private function upsertProductUnitsFromEntry(
        Tenant $tenant,
        int $productId,
        int $warehouseId,
        int $movementId,
        array $serialUnits,
        $now,
    ): void {
        foreach ($serialUnits as $serialUnit) {
            DB::table('product_units')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'serial_type' => $serialUnit['serial_type'],
                    'serial_number' => $serialUnit['serial_number'],
                ],
                [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'status' => 'available',
                    'acquired_stock_movement_id' => $movementId,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function applyInventoryTransfer(Tenant $tenant, array $payload): string
    {
        $fromWarehouse = $this->warehouseByCode($tenant, $this->requiredString($payload, 'from_warehouse_code'));
        $toWarehouse = $this->warehouseByCode($tenant, $this->requiredString($payload, 'to_warehouse_code'));
        $sourceId = (int) ($payload['id'] ?? 0);
        $now = now();

        // Upsert por (tenant_id, document_number): mas estable que el id local
        // porque el cloud puede tener ya una fila con el mismo id (de seeds u
        // otros locales) y choca el unique constraint. El document_number es
        // semanticamente unico por tenant.
        $transferId = $this->upsertAndGetId(
            'inventory_transfers',
            [
                'tenant_id' => $tenant->id,
                'document_number' => $this->requiredString($payload, 'document_number'),
            ],
            [
                'sequence' => $sourceId > 0 ? $sourceId : DB::table('inventory_transfers')->where('tenant_id', $tenant->id)->max('sequence') + 1,
                'guide_number' => $this->nullableString($payload['guide_number'] ?? null),
                'type' => $this->nullableString($payload['type'] ?? null) ?? 'internal',
                'validation_mode' => $this->nullableString($payload['validation_mode'] ?? null) ?? 'simple',
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'status' => $this->nullableString($payload['status'] ?? null) ?? 'completed',
                'reason' => $this->nullableString($payload['reason'] ?? null),
                'reference' => $this->nullableString($payload['reference'] ?? null),
                'notes' => $this->nullableString($payload['notes'] ?? null),
                'resolution_status' => $this->nullableString($payload['resolution_status'] ?? null) ?? 'unresolved',
                'processed_at' => isset($payload['processed_at']) ? Carbon::parse($payload['processed_at']) : null,
                'prepared_at' => isset($payload['prepared_at']) ? Carbon::parse($payload['prepared_at']) : null,
                'dispatched_at' => isset($payload['dispatched_at']) ? Carbon::parse($payload['dispatched_at']) : null,
                'received_at' => isset($payload['received_at']) ? Carbon::parse($payload['received_at']) : null,
                'cancelled_at' => isset($payload['cancelled_at']) ? Carbon::parse($payload['cancelled_at']) : null,
                'resolved_at' => isset($payload['resolved_at']) ? Carbon::parse($payload['resolved_at']) : null,
                'updated_at' => $now,
            ]
        );

        if ($transferId <= 0) {
            return 'ignored';
        }

        // Reemplazar items para que coincidan exactamente con el payload.
        foreach ($payload['items'] ?? [] as $itemPayload) {
            $product = $this->productBySku($tenant, $this->requiredString($itemPayload, 'sku'));
            // Upsert por (tenant_id, inventory_transfer_id, product_id) en lugar
            // del id local: el id del local puede chocar con data existente en
            // la nube (seed u otros locales). La llave semantica es
            // "un item por producto por traslado" y eso es lo que usamos.
            $this->upsertAndGetId(
                'inventory_transfer_items',
                [
                    'tenant_id' => $tenant->id,
                    'inventory_transfer_id' => $transferId,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => $itemPayload['quantity'] ?? 0,
                    'requested_quantity' => $itemPayload['requested_quantity'] ?? ($itemPayload['quantity'] ?? 0),
                    'prepared_quantity' => $itemPayload['prepared_quantity'] ?? ($itemPayload['quantity'] ?? 0),
                    'received_quantity' => $itemPayload['received_quantity'] ?? ($itemPayload['quantity'] ?? 0),
                    'difference_quantity' => $itemPayload['difference_quantity'] ?? 0,
                    'updated_at' => $now,
                ]
            );
        }

        return 'applied';
    }

    /**
     * Cross-tenant (no BelongsToTenant). Crea la fila en inventory_transfer_requests
     * con status=requested. Es idempotente por (origin_tenant_id, sequence).
     */
    private function applyInventoryTransferRequestCreated(Tenant $tenant, array $payload): string
    {
        return $this->upsertTransferRequest($payload, [
            'status' => 'requested',
        ]);
    }

    /**
     * Cross-tenant. Replica el efecto del accept local: descuenta stock en la
     * empresa que responde y lo ingresa en la empresa solicitante.
     * Es idempotente: si el request ya esta completed, no hace nada.
     */
    private function applyInventoryTransferRequestAccepted(Tenant $tenant, array $payload): string
    {
        $originTenantId = (int) $payload['origin_tenant_id'];
        $sequence = (int) $payload['sequence'];

        $existing = DB::table('inventory_transfer_requests')
            ->where('origin_tenant_id', $originTenantId)
            ->where('sequence', $sequence)
            ->first();

        if ($existing && $existing->status === 'completed') {
            return 'applied';
        }

        return DB::transaction(function () use ($payload): string {
            $this->upsertTransferRequest($payload, [
                'status' => 'completed',
                'destination_warehouse_id' => $payload['destination_warehouse_id'] ?? null,
                'response_notes' => $payload['response_notes'] ?? null,
                'responded_by' => $payload['responded_by'] ?? null,
                'responded_at' => isset($payload['responded_at']) ? Carbon::parse($payload['responded_at']) : now(),
                'completed_at' => isset($payload['completed_at']) ? Carbon::parse($payload['completed_at']) : now(),
            ]);

            $requestId = (int) DB::table('inventory_transfer_requests')
                ->where('origin_tenant_id', (int) $payload['origin_tenant_id'])
                ->where('sequence', (int) $payload['sequence'])
                ->value('id');

            $items = $payload['items'] ?? [];
            $flowType = $payload['flow_type'] ?? 'stock_request';
            $senderTenantId = (int) ($payload['sender_tenant_id'] ?? $payload['destination_tenant_id']);
            $receiverTenantId = (int) ($payload['receiver_tenant_id'] ?? $payload['origin_tenant_id']);
            $senderWarehouseId = (int) ($payload['sender_warehouse_id'] ?? $payload['destination_warehouse_id'] ?? 0);
            $receiverWarehouseId = (int) ($payload['receiver_warehouse_id'] ?? $payload['from_warehouse_id']);
            foreach ($items as $itemPayload) {
                $this->applyTransferRequestItemAccepted(
                    $requestId,
                    $senderTenantId,
                    $receiverTenantId,
                    $senderWarehouseId,
                    $receiverWarehouseId,
                    $flowType,
                    $itemPayload,
                );
            }

            return 'applied';
        });
    }

    /**
     * Cross-tenant. Solo actualiza status. No toca stock.
     */
    private function applyInventoryTransferRequestRejected(Tenant $tenant, array $payload): string
    {
        return $this->upsertTransferRequest($payload, [
            'status' => 'rejected',
            'response_notes' => $payload['response_notes'] ?? null,
            'responded_by' => $payload['responded_by'] ?? null,
            'responded_at' => isset($payload['responded_at']) ? Carbon::parse($payload['responded_at']) : now(),
        ]);
    }

    /**
     * Cross-tenant. Solo actualiza status. No toca stock.
     */
    private function applyInventoryTransferRequestCancelled(Tenant $tenant, array $payload): string
    {
        return $this->upsertTransferRequest($payload, [
            'status' => 'cancelled',
            'responded_by' => $payload['responded_by'] ?? null,
            'responded_at' => isset($payload['responded_at']) ? Carbon::parse($payload['responded_at']) : now(),
        ]);
    }

    /**
     * Upsert cross-tenant (no BelongsToTenant). Llave semantica:
     * (origin_tenant_id, sequence). Retorna 'applied' o 'ignored'.
     */
    private function upsertTransferRequest(array $payload, array $overrides): string
    {
        $originTenantId = (int) $payload['origin_tenant_id'];
        $sequence = (int) $payload['sequence'];

        if ($originTenantId <= 0 || $sequence <= 0) {
            return 'ignored';
        }

        $now = now();
        $base = [
            'document_number' => $payload['document_number'] ?? null,
            'origin_tenant_id' => $originTenantId,
            'destination_tenant_id' => (int) ($payload['destination_tenant_id'] ?? 0),
            'flow_type' => $payload['flow_type'] ?? 'stock_request',
            'initiated_by_tenant_id' => $payload['initiated_by_tenant_id'] ?? $originTenantId,
            'sender_tenant_id' => $payload['sender_tenant_id'] ?? ($payload['destination_tenant_id'] ?? null),
            'receiver_tenant_id' => $payload['receiver_tenant_id'] ?? $originTenantId,
            'from_warehouse_id' => (int) ($payload['from_warehouse_id'] ?? 0),
            'sender_warehouse_id' => $payload['sender_warehouse_id'] ?? ($payload['destination_warehouse_id'] ?? null),
            'receiver_warehouse_id' => $payload['receiver_warehouse_id'] ?? ($payload['from_warehouse_id'] ?? null),
            'logistics_mode' => (bool) ($payload['logistics_mode'] ?? false),
            'reason' => $payload['reason'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'requested_by' => $payload['requested_by'] ?? null,
            'requested_at' => isset($payload['requested_at']) ? Carbon::parse($payload['requested_at']) : null,
            'updated_at' => $now,
        ];

        DB::table('inventory_transfer_requests')->updateOrInsert(
            [
                'origin_tenant_id' => $originTenantId,
                'sequence' => $sequence,
            ],
            array_merge($base, $overrides),
        );

        $requestId = (int) DB::table('inventory_transfer_requests')
            ->where('origin_tenant_id', $originTenantId)
            ->where('sequence', $sequence)
            ->value('id');

        // Items solo se replican cuando ya existe el header (en el caso created es
        // el primer evento; en accepted se reemplaza para que coincida con el
        // payload final post-accept).
        $items = $payload['items'] ?? [];
        if ($items !== []) {
            DB::table('inventory_transfer_request_items')
                ->where('inventory_transfer_request_id', $requestId)
                ->delete();

            foreach ($items as $itemPayload) {
                DB::table('inventory_transfer_request_items')->insert([
                    'inventory_transfer_request_id' => $requestId,
                    'origin_product_id' => (int) ($itemPayload['origin_product_id'] ?? 0),
                    'destination_product_id' => $itemPayload['destination_product_id'] ?? null,
                    'quantity' => $itemPayload['quantity'] ?? 0,
                    'product_unit_ids' => isset($itemPayload['product_unit_ids'])
                        ? json_encode($itemPayload['product_unit_ids'])
                        : null,
                    'serial_units' => isset($itemPayload['serial_units'])
                        ? json_encode($itemPayload['serial_units'])
                        : null,
                    'out_stock_movement_id' => $itemPayload['out_stock_movement_id'] ?? null,
                    'in_stock_movement_id' => $itemPayload['in_stock_movement_id'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        return 'applied';
    }

    /**
     * Aplica la salida en la empresa que responde y la entrada en la solicitante.
     */
    private function applyTransferRequestItemAccepted(
        int $requestId,
        int $senderTenantId,
        int $receiverTenantId,
        int $senderWarehouseId,
        int $receiverWarehouseId,
        string $flowType,
        array $itemPayload,
    ): void {
        $tenantManager = app(TenantManager::class);
        $originalTenant = $tenantManager->current();

        $originProductId = (int) ($itemPayload['origin_product_id'] ?? 0);
        $destinationProductId = (int) ($itemPayload['destination_product_id'] ?? 0);
        $senderProductId = $flowType === 'shipment_offer' ? $originProductId : $destinationProductId;
        $receiverProductId = $flowType === 'shipment_offer' ? $destinationProductId : $originProductId;
        $quantity = (float) ($itemPayload['quantity'] ?? 0);

        if ($senderTenantId <= 0 || $receiverTenantId <= 0
            || $senderProductId <= 0 || $receiverProductId <= 0
            || $senderWarehouseId <= 0 || $receiverWarehouseId <= 0
            || $quantity <= 0.0) {
            return;
        }

        $documentNumber = (string) DB::table('inventory_transfer_requests')
            ->where('id', $requestId)
            ->value('document_number');

        try {
            $tenantManager->set(Tenant::query()->findOrFail($senderTenantId));
            $destinationExitDocNumber = $documentNumber ? $documentNumber.'-OUT' : 'TREQ-OUT-'.$requestId;
            $outMovementId = $this->createCloudProductExit(
                tenantId: $senderTenantId,
                productId: $senderProductId,
                warehouseId: $senderWarehouseId,
                quantity: $quantity,
                documentNumber: $destinationExitDocNumber,
                serialUnits: $itemPayload['serial_units'] ?? [],
            );

            $tenantManager->set(Tenant::query()->findOrFail($receiverTenantId));
            $originEntryDocNumber = $documentNumber ? $documentNumber.'-IN' : 'TREQ-IN-'.$requestId;
            $inMovementId = $this->createCloudProductEntry(
                tenantId: $receiverTenantId,
                productId: $receiverProductId,
                warehouseId: $receiverWarehouseId,
                quantity: $quantity,
                documentNumber: $originEntryDocNumber,
                serialUnits: $itemPayload['serial_units'] ?? [],
            );

            DB::table('inventory_transfer_request_items')
                ->where('inventory_transfer_request_id', $requestId)
                ->where('origin_product_id', $originProductId)
                ->update([
                    'destination_product_id' => $destinationProductId,
                    'out_stock_movement_id' => $outMovementId,
                    'in_stock_movement_id' => $inMovementId,
                    'updated_at' => now(),
                ]);
        } finally {
            if ($originalTenant) {
                $tenantManager->set($originalTenant);
            }
        }
    }

    /**
     * Crea un product_exit en la nube (replica de InventoryMovementService::adjustmentOut)
     * sin pasar por el servicio (que require TenantManager::require() y asume scope local).
     * Idempotente por (tenant_id, document_number).
     * Retorna el stock_movement_id.
     */
    private function createCloudProductExit(
        int $tenantId,
        int $productId,
        int $warehouseId,
        float $quantity,
        string $documentNumber,
        array $serialUnits,
    ): int {
        $now = now();

        $existing = DB::table('product_exits')
            ->where('tenant_id', $tenantId)
            ->where('document_number', $documentNumber)
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        $productUnitIds = [];
        if ($serialUnits !== []) {
            $productUnitIds = DB::table('product_units')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'available')
                ->where(function ($query) use ($serialUnits): void {
                    foreach ($serialUnits as $serialUnit) {
                        $query->orWhere(function ($unitQuery) use ($serialUnit): void {
                            $unitQuery
                                ->where('serial_type', $serialUnit['serial_type'] ?? '')
                                ->where('serial_number', $serialUnit['serial_number'] ?? '');
                        });
                    }
                })
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (count($productUnitIds) !== count($serialUnits)) {
                throw new RuntimeException('No se encontraron todos los IMEIs disponibles para aplicar la salida interempresa.');
            }
        }

        $sequence = ((int) DB::table('product_exits')->where('tenant_id', $tenantId)->max('sequence')) + 1;

        $exitId = (int) DB::table('product_exits')->insertGetId([
            'tenant_id' => $tenantId,
            'sequence' => $sequence,
            'document_number' => $documentNumber,
            'reason' => "Salida interempresa {$documentNumber}",
            'reference' => null,
            'notes' => null,
            'status' => 'processed',
            'processed_at' => $now,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Decrementa stock_balance.
        $stockBalance = DB::table('stock_balances')
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if ($stockBalance) {
            DB::table('stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->update([
                    'quantity_available' => (float) $stockBalance->quantity_available - $quantity,
                ]);
        } else {
            DB::table('stock_balances')->insert([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity_available' => -$quantity,
                'quantity_reserved' => 0,
                'quantity_damaged' => 0,
            ]);
        }

        $movementId = (int) DB::table('stock_movements')->insertGetId([
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'type' => 'exit',
            'quantity' => $quantity,
            'unit_cost' => null,
            'reason' => "Salida interempresa {$documentNumber}",
            'reference_type' => 'product_exit',
            'reference_id' => $exitId,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('product_exit_items')->insert([
            'tenant_id' => $tenantId,
            'product_exit_id' => $exitId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'stock_movement_id' => $movementId,
            'product_unit_ids' => $productUnitIds !== [] ? json_encode($productUnitIds) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Marca ProductUnit REMOVED si vienen IDs (serializados).
        if ($productUnitIds !== []) {
            DB::table('product_units')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $productUnitIds)
                ->update([
                    'status' => 'removed',
                    'warehouse_id' => null,
                    'released_stock_movement_id' => $movementId,
                    'updated_at' => $now,
                ]);
        }

        return $movementId;
    }

    /**
     * Crea un product_entry en la nube (replica de InventoryMovementService::purchase).
     * Idempotente por (tenant_id, document_number).
     * Retorna el stock_movement_id.
     */
    private function createCloudProductEntry(
        int $tenantId,
        int $productId,
        int $warehouseId,
        float $quantity,
        string $documentNumber,
        array $serialUnits,
    ): int {
        $now = now();

        $existing = DB::table('product_entries')
            ->where('tenant_id', $tenantId)
            ->where('document_number', $documentNumber)
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        $sequence = ((int) DB::table('product_entries')->where('tenant_id', $tenantId)->max('sequence')) + 1;

        $entryId = (int) DB::table('product_entries')->insertGetId([
            'tenant_id' => $tenantId,
            'sequence' => $sequence,
            'document_number' => $documentNumber,
            'reason' => "Entrada interempresa {$documentNumber}",
            'reference' => null,
            'notes' => null,
            'status' => 'processed',
            'processed_at' => $now,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stockBalance = DB::table('stock_balances')
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if ($stockBalance) {
            DB::table('stock_balances')
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->update([
                    'quantity_available' => (float) $stockBalance->quantity_available + $quantity,
                ]);
        } else {
            DB::table('stock_balances')->insert([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity_available' => $quantity,
                'quantity_reserved' => 0,
                'quantity_damaged' => 0,
            ]);
        }

        $movementId = (int) DB::table('stock_movements')->insertGetId([
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'type' => 'entry',
            'quantity' => $quantity,
            'unit_cost' => null,
            'reason' => "Entrada interempresa {$documentNumber}",
            'reference_type' => 'product_entry',
            'reference_id' => $entryId,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('product_entry_items')->insert([
            'tenant_id' => $tenantId,
            'product_entry_id' => $entryId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_cost' => null,
            'stock_movement_id' => $movementId,
            'serial_units' => $serialUnits !== [] ? json_encode($serialUnits) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Crea ProductUnit AVAILABLE si el producto es serializado.
        if ($serialUnits !== []) {
            foreach ($serialUnits as $serialUnit) {
                if (! isset($serialUnit['serial_type'], $serialUnit['serial_number'])) {
                    continue;
                }

                DB::table('product_units')->insert([
                    'tenant_id' => $tenantId,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'serial_type' => $serialUnit['serial_type'],
                    'serial_number' => $serialUnit['serial_number'],
                    'status' => 'available',
                    'acquired_stock_movement_id' => $movementId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        return $movementId;
    }

    /**
     * Aplica un evento product.image.{uploaded,updated} en la BD local.
     * NO descarga el archivo binario — esa parte la hace SyncDownloadService
     * (Fase 3, todavia no implementado). Aqui solo replicamos la fila y las
     * 3 variantes para que el ProductResource funcione localmente.
     */
    private function applyProductImage(Tenant $tenant, array $payload): string
    {
        $uuid = $payload['uuid'] ?? null;
        if (! $uuid) {
            return 'skipped:missing_uuid';
        }

        $productId = $this->resolveProductIdForImage($tenant, $payload);

        $image = ProductImage::query()
            ->withTrashed()
            ->firstOrNew(['uuid' => $uuid, 'tenant_id' => $tenant->id]);

        $image->fill([
            'product_id' => $productId,
            'mime' => $payload['mime'] ?? $image->mime ?? 'image/webp',
            'size' => $payload['size'] ?? $image->size ?? 0,
            'width' => $payload['width'] ?? $image->width,
            'height' => $payload['height'] ?? $image->height,
            'sha256' => $payload['sha256'] ?? $image->sha256,
            'alt' => $payload['alt'] ?? $image->alt,
            'sort' => $payload['sort'] ?? $image->sort ?? 0,
            'is_primary' => (bool) ($payload['is_primary'] ?? $image->is_primary ?? false),
            'deleted_at' => null,
        ]);

        // Si el path apunta al cloud, guardamos la URL en storage_path como
        // marcador temporal hasta que SyncDownloadService baje el archivo.
        // Mientras tanto, ProductImageResource::url() devuelve esa URL remota
        // y el frontend puede servirla directo.
        $image->storage_path = $payload['cloud_url'] ?? $image->storage_path;
        $image->save();

        // Variantes.
        foreach (($payload['variants'] ?? []) as $variantName => $variantData) {
            $variant = ProductImageVariant::query()
                ->updateOrCreate(
                    [
                        'product_image_id' => $image->id,
                        'variant' => $variantName,
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'storage_path' => $variantData['cloud_url'] ?? '',
                        'mime' => $variantData['mime'] ?? 'image/webp',
                        'size' => $variantData['size'] ?? 0,
                        'width' => $variantData['width'] ?? 0,
                        'height' => $variantData['height'] ?? 0,
                    ],
                );
        }

        // Side effect: descargar el archivo binario al synced-images local.
        // El proxy LocalImageProxyController sirve desde synced-images primero,
        // y hace 302 al cloud si no esta. La descarga corre en background
        // (no bloqueamos el response del applier).
        dispatch(function () use ($image) {
            try {
                app(SyncDownloadService::class)->downloadImage($image);
            } catch (\Throwable $e) {
                Log::warning('sync.image.background_download_failed', [
                    'image_id' => $image->id,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();

        return "product_image:{$image->id}";
    }

    private function resolveProductIdForImage(Tenant $tenant, array $payload): int
    {
        $sku = $this->nullableString($payload['product_sku'] ?? $payload['sku'] ?? null);

        if ($sku !== null) {
            return (int) $this->productBySku($tenant, $sku)->id;
        }

        $legacyProductId = (int) ($payload['product_id'] ?? 0);
        if ($legacyProductId > 0) {
            $exists = DB::table('products')
                ->where('tenant_id', $tenant->id)
                ->where('id', $legacyProductId)
                ->exists();

            if ($exists) {
                return $legacyProductId;
            }
        }

        throw new RuntimeException('No se encontro el producto de la imagen para aplicar el evento.');
    }

    /**
     * Aplica product.image.deleted. Solo soft-delete local (los archivos se
     * borran del storage despues de 30d via job de limpieza, Nivel 3).
     */
    private function applyProductImageDeleted(Tenant $tenant, array $payload): string
    {
        $uuid = $payload['uuid'] ?? null;
        if (! $uuid) {
            return 'skipped:missing_uuid';
        }

        $image = ProductImage::query()
            ->where('uuid', $uuid)
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($image) {
            $image->delete();
        }

        return "product_image_deleted:{$uuid}";
    }

    private function applyProductVariant(Tenant $tenant, array $payload): string
    {
        $productSku = $this->requiredString($payload, 'product_sku');
        $product = $this->productBySku($tenant, $productSku);

        $attributes = [
            'product_id' => (int) $product->id,
            'color' => $payload['color'] ?? null,
            'color_hex' => $payload['color_hex'] ?? null,
            'sku_variant' => $payload['sku_variant'] ?? null,
            'barcode_variant' => $payload['barcode_variant'] ?? null,
            'price_override' => $payload['price_override'] ?? null,
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'position' => (int) ($payload['position'] ?? 0),
        ];

        $match = [
            'tenant_id' => $tenant->id,
            'product_id' => (int) $product->id,
            'color' => $attributes['color'],
            'sku_variant' => $attributes['sku_variant'],
        ];

        $this->upsertByKeys('product_variants', $match, $attributes);

        return "product_variant_upserted:{$product->id}:{$attributes['color']}";
    }

    private function applyProductVariantDeleted(Tenant $tenant, array $payload): string
    {
        $productSku = $this->requiredString($payload, 'product_sku');
        $product = $this->productBySku($tenant, $productSku);

        $variant = ProductVariant::query()
            ->where('tenant_id', $tenant->id)
            ->where('product_id', (int) $product->id)
            ->when(isset($payload['color']), fn ($query) => $query->where('color', $payload['color']))
            ->when(isset($payload['sku_variant']), fn ($query) => $query->where('sku_variant', $payload['sku_variant']))
            ->first();

        if ($variant) {
            $variant->delete();

            return "product_variant_deleted:{$variant->id}";
        }

        return 'product_variant_deleted:missing';
    }

    private function applyProductUnit(Tenant $tenant, array $payload): string
    {
        $product = $this->productBySku($tenant, $this->requiredString($payload, 'sku'));
        $warehouse = trim((string) ($payload['warehouse_code'] ?? '')) !== ''
            ? $this->warehouseByCode($tenant, (string) $payload['warehouse_code'])
            : null;

        $this->upsertByKeys(
            'product_units',
            [
                'tenant_id' => $tenant->id,
                'serial_type' => $payload['serial_type'] ?? 'serial',
                'serial_number' => $this->requiredString($payload, 'serial_number'),
            ],
            [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse?->id,
                'status' => $payload['status'] ?? 'available',
                'acquired_stock_movement_id' => null,
                'released_stock_movement_id' => null,
                'updated_at' => now(),
            ]
        );

        return 'applied';
    }

    private function applyPriceList(Tenant $tenant, array $payload): string
    {
        $code = mb_strtoupper($this->requiredString($payload, 'code'));
        $now = now();
        $isDefault = array_key_exists('is_default', $payload) ? (bool) $payload['is_default'] : false;

        if ($isDefault) {
            DB::table('price_lists')->where('tenant_id', $tenant->id)->update([
                'is_default' => false,
                'updated_at' => $now,
            ]);
        }

        $this->upsertByKeys(
            'price_lists',
            ['tenant_id' => $tenant->id, 'code' => $code],
            [
                'name' => $this->requiredString($payload, 'name'),
                'description' => $payload['description'] ?? null,
                'markup_percentage' => array_key_exists('markup_percentage', $payload) && $payload['markup_percentage'] !== null
                    ? (float) $payload['markup_percentage']
                    : null,
                'is_default' => $isDefault,
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
                'updated_at' => $now,
            ]
        );

        $priceList = DB::table('price_lists')->where('tenant_id', $tenant->id)->where('code', $code)->first();
        $this->syncPriceListPaymentMethods($tenant, (int) $priceList->id, $payload['payment_method_codes'] ?? null);

        return 'applied';
    }

    private function applyProductPrice(Tenant $tenant, array $payload): string
    {
        $product = $this->productBySku($tenant, $this->requiredString($payload, 'sku'));
        $priceList = $this->priceListByCode($tenant, $this->requiredString($payload, 'price_list_code'));
        $now = now();

        $this->upsertByKeys(
            'product_prices',
            [
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'price_list_id' => $priceList->id,
            ],
            [
                'price' => $payload['price'],
                'currency' => strtoupper($payload['currency'] ?? 'USD'),
                'exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $payload['exchange_rate_type_code'] ?? null, $payload['exchange_rate_type_id'] ?? null),
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                'updated_at' => $now,
            ]
        );

        $this->recordProductAudit((int) $product->id, [], [
            'product_price' => [
                'price_list_id' => (int) $priceList->id,
                'price' => round((float) $payload['price'], 4),
                'currency' => strtoupper($payload['currency'] ?? 'USD'),
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            ],
        ]);

        return 'applied';
    }

    private function applyExchangeRateType(Tenant $tenant, array $payload): string
    {
        $code = mb_strtoupper($this->requiredString($payload, 'code'));
        $now = now();
        $isDefault = array_key_exists('is_default', $payload) ? (bool) $payload['is_default'] : false;

        if ($isDefault) {
            DB::table('exchange_rate_types')->where('tenant_id', $tenant->id)->update([
                'is_default' => false,
                'updated_at' => $now,
            ]);
        }

        $this->upsertByKeys(
            'exchange_rate_types',
            ['tenant_id' => $tenant->id, 'code' => $code],
            [
                'name' => $this->requiredString($payload, 'name'),
                'is_default' => $isDefault,
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                'updated_at' => $now,
            ]
        );

        return 'applied';
    }

    private function applyExchangeRate(Tenant $tenant, array $payload): string
    {
        $typeId = $this->exchangeRateTypeId($tenant, $payload['exchange_rate_type_code'] ?? null, $payload['exchange_rate_type_id'] ?? null);

        if (! $typeId) {
            throw new RuntimeException('No se encontro el tipo de tasa para aplicar la tasa recibida.');
        }

        $now = now();
        $effectiveAt = Carbon::parse($payload['effective_at'] ?? $now);
        $baseCurrency = strtoupper($payload['base_currency'] ?? 'USD');
        $quoteCurrency = strtoupper($payload['quote_currency'] ?? 'VES');

        $this->upsertByKeys(
            'exchange_rates',
            [
                'tenant_id' => $tenant->id,
                'exchange_rate_type_id' => $typeId,
                'base_currency' => $baseCurrency,
                'quote_currency' => $quoteCurrency,
                'effective_at' => $effectiveAt,
            ],
            [
                'rate' => $payload['rate'],
                'source' => $payload['source'] ?? 'sync',
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                'updated_at' => $now,
            ]
        );

        return 'applied';
    }

    private function applyWarrantyPolicy(Tenant $tenant, array $payload): string
    {
        $name = $this->requiredString($payload, 'name');

        $this->upsertByKeys(
            'warranty_policies',
            ['tenant_id' => $tenant->id, 'name' => $name],
            [
                'duration_days' => (int) ($payload['duration_days'] ?? 0),
                'coverage_type' => $payload['coverage_type'] ?? 'store',
                'conditions' => $payload['conditions'] ?? null,
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                'updated_at' => now(),
            ]
        );

        return 'applied';
    }

    private function applySupplier(Tenant $tenant, array $payload): string
    {
        $documentType = $payload['document_type'] ?? null;
        $documentNumber = $payload['document_number'] ?? null;
        $keys = [
            'tenant_id' => $tenant->id,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
        ];

        if ($documentType === null || $documentNumber === null) {
            $keys = ['tenant_id' => $tenant->id, 'name' => $this->requiredString($payload, 'name')];
        }

        $this->upsertByKeys('suppliers', $keys, [
            'name' => $this->requiredString($payload, 'name'),
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'phone' => $payload['phone'] ?? null,
            'email' => $payload['email'] ?? null,
            'fiscal_address' => $payload['fiscal_address'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'updated_at' => now(),
        ]);

        return 'applied';
    }

    private function applyBrand(Tenant $tenant, array $payload): string
    {
        $this->upsertByKeys('brands', ['tenant_id' => $tenant->id, 'slug' => $this->requiredString($payload, 'slug')], [
            'name' => $this->requiredString($payload, 'name'),
            'description' => $payload['description'] ?? null,
            'is_active' => ($payload['is_active'] ?? true) && ! (($payload['_deleted'] ?? false)),
            'updated_at' => now(),
        ]);

        return 'applied';
    }

    private function applyCategory(Tenant $tenant, array $payload): string
    {
        $parentId = null;
        if (! empty($payload['parent_slug'])) {
            $parentId = DB::table('categories')
                ->where('tenant_id', $tenant->id)
                ->where('slug', $payload['parent_slug'])
                ->value('id');
        }

        $this->upsertByKeys('categories', ['tenant_id' => $tenant->id, 'slug' => $this->requiredString($payload, 'slug')], [
            'parent_id' => $parentId,
            'name' => $this->requiredString($payload, 'name'),
            'description' => $payload['description'] ?? null,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'is_active' => ($payload['is_active'] ?? true) && ! (($payload['_deleted'] ?? false)),
            'updated_at' => now(),
        ]);

        return 'applied';
    }

    private function applyTag(Tenant $tenant, array $payload): string
    {
        if (($payload['_deleted'] ?? false) === true) {
            DB::table('tags')->where('tenant_id', $tenant->id)->where('slug', $payload['slug'] ?? '')->delete();

            return 'applied';
        }

        $this->upsertByKeys('tags', ['tenant_id' => $tenant->id, 'slug' => $this->requiredString($payload, 'slug')], [
            'name' => $this->requiredString($payload, 'name'),
            'color' => $payload['color'] ?? null,
            'updated_at' => now(),
        ]);

        return 'applied';
    }

    private function applyPaymentMethod(Tenant $tenant, array $payload): string
    {
        $code = mb_strtoupper($this->requiredString($payload, 'code'));
        $now = now();

        $this->upsertByKeys(
            'payment_methods',
            ['tenant_id' => $tenant->id, 'code' => $code],
            [
                'name' => $this->requiredString($payload, 'name'),
                'method' => $payload['method'] ?? 'cash',
                'currency_mode' => $payload['currency_mode'] ?? 'flexible',
                'requires_reference' => array_key_exists('requires_reference', $payload) ? (bool) $payload['requires_reference'] : false,
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
                'updated_at' => $now,
            ]
        );

        return 'applied';
    }

    private function applyCashRegister(Tenant $tenant, array $payload): string
    {
        $code = mb_strtoupper($this->requiredString($payload, 'code'));
        $branch = $this->branchByCode($tenant, $this->requiredString($payload, 'branch_code'));

        $this->upsertByKeys(
            'cash_registers',
            ['tenant_id' => $tenant->id, 'code' => $code],
            [
                'branch_id' => $branch->id,
                'name' => $this->requiredString($payload, 'name'),
                'status' => $payload['status'] ?? 'active',
                'notes' => $payload['notes'] ?? null,
                'updated_at' => now(),
            ]
        );

        return 'applied';
    }

    private function applyPosOrder(Tenant $tenant, array $payload, array $event): string
    {
        $sourceNodeCode = $this->sourceNodeCode($tenant, $event, $payload);
        $orderPayload = $payload['order'] ?? $payload;
        $salePayload = $payload['sale'] ?? [];
        $sourceOrderId = (int) ($orderPayload['id'] ?? $payload['order_id'] ?? $event['aggregate_id'] ?? 0);
        $sourceSaleId = (int) ($salePayload['id'] ?? $payload['sale_id'] ?? 0);

        if ($sourceOrderId <= 0 || $sourceSaleId <= 0) {
            return 'ignored';
        }

        $now = now();
        $paidAt = $this->nullableDate($orderPayload['paid_at'] ?? $payload['paid_at'] ?? null);
        $closedAt = $this->nullableDate($orderPayload['closed_at'] ?? $payload['closed_at'] ?? null);
        $openedAt = $this->nullableDate($orderPayload['opened_at'] ?? $payload['opened_at'] ?? null) ?? $now;
        $status = $orderPayload['status'] ?? $payload['status'] ?? 'open';
        $saleStatus = $salePayload['status'] ?? $payload['sale_status'] ?? ($status === 'paid' ? 'confirmed' : 'draft');
        $cancelledAt = $this->nullableDate($salePayload['cancelled_at'] ?? null);

        $customerId = $this->customerIdByDocument(
            $tenant,
            $payload['customer']['document_type'] ?? $orderPayload['customer_document_type'] ?? null,
            $payload['customer']['document_number'] ?? $orderPayload['customer_document_number'] ?? null,
        );

        $previousSaleStatus = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->where('sync_source_node_code', $sourceNodeCode)
            ->where('sync_source_id', $sourceSaleId)
            ->value('status');

        $saleId = $this->upsertAndGetId(
            'sales',
            [
                'tenant_id' => $tenant->id,
                'sync_source_node_code' => $sourceNodeCode,
                'sync_source_id' => $sourceSaleId,
            ],
            [
                'status' => $saleStatus,
                'customer_id' => $customerId,
                'total_base_amount' => $salePayload['total_base_amount'] ?? $orderPayload['total_base_amount'] ?? $payload['total_base_amount'] ?? 0,
                'total_local_amount' => $salePayload['total_local_amount'] ?? $orderPayload['total_local_amount'] ?? $payload['total_local_amount'] ?? 0,
                'created_by' => null,
                'confirmed_at' => $this->nullableDate($salePayload['confirmed_at'] ?? null) ?? ($saleStatus === 'confirmed' ? ($paidAt ?? $closedAt ?? $now) : null),
                'cancelled_at' => $cancelledAt,
                'updated_at' => $now,
            ]
        );

        $orderId = $this->upsertAndGetId(
            'pos_orders',
            [
                'tenant_id' => $tenant->id,
                'sync_source_node_code' => $sourceNodeCode,
                'sync_source_id' => $sourceOrderId,
            ],
            [
                'sale_id' => $saleId,
                'cash_register_session_id' => null,
                'customer_id' => $customerId,
                'status' => $status,
                'cashier_id' => null,
                'customer_name' => $orderPayload['customer_name'] ?? $payload['customer_name'] ?? 'Consumidor final',
                'sync_branch_name' => $orderPayload['branch_name'] ?? $payload['cash_register']['branch_name'] ?? null,
                'sync_cash_register_name' => $orderPayload['cash_register_name'] ?? $payload['cash_register']['name'] ?? null,
                'sync_cashier_name' => $orderPayload['cashier_name'] ?? $payload['cashier']['name'] ?? null,
                'sync_customer_document_type' => $payload['customer']['document_type'] ?? $orderPayload['customer_document_type'] ?? null,
                'sync_customer_document_number' => $payload['customer']['document_number'] ?? $orderPayload['customer_document_number'] ?? null,
                'total_base_amount' => $orderPayload['total_base_amount'] ?? $payload['total_base_amount'] ?? 0,
                'total_local_amount' => $orderPayload['total_local_amount'] ?? $payload['total_local_amount'] ?? 0,
                'paid_base_amount' => $orderPayload['paid_base_amount'] ?? $payload['paid_base_amount'] ?? 0,
                'paid_local_amount' => $orderPayload['paid_local_amount'] ?? $payload['paid_local_amount'] ?? 0,
                'opened_at' => $openedAt,
                'paid_at' => $paidAt,
                'closed_at' => $closedAt,
                'updated_at' => $now,
            ]
        );

        $this->syncPosSaleItems(
            $tenant,
            $saleId,
            $sourceNodeCode,
            $saleStatus,
            $previousSaleStatus !== 'confirmed',
            $payload['items'] ?? []
        );
        $this->syncPosPayments($tenant, $orderId, $sourceNodeCode, $payload['payments'] ?? []);
        $this->syncPosReceivable($tenant, $saleId, $sourceNodeCode, $payload['receivable'] ?? null);

        return 'applied';
    }

    private function syncPosSaleItems(Tenant $tenant, int $saleId, string $sourceNodeCode, string $saleStatus, bool $applyStock, array $items): void
    {
        if ($items === []) {
            return;
        }

        $sourceIds = [];
        $now = now();

        foreach ($items as $item) {
            $sourceId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }

            $product = $this->productBySku($tenant, $this->requiredString($item, 'product_sku'));
            $warehouse = $this->warehouseByCode($tenant, $this->requiredString($item, 'warehouse_code'));
            $priceListId = $this->nullablePriceListIdByCode($tenant, $item['price_list_code'] ?? null);
            $sourceIds[] = $sourceId;

            $this->upsertByKeys(
                'sale_items',
                [
                    'tenant_id' => $tenant->id,
                    'sync_source_node_code' => $sourceNodeCode,
                    'sync_source_id' => $sourceId,
                ],
                [
                    'sale_id' => $saleId,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'price_list_id' => $priceListId,
                    'price_list_name' => $item['price_list_name'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'sale_currency' => strtoupper($item['sale_currency'] ?? 'USD'),
                    'unit_price' => $item['unit_price'] ?? 0,
                    'total_amount' => $item['total_amount'] ?? 0,
                    'base_unit_price' => $item['base_unit_price'] ?? 0,
                    'base_total_amount' => $item['base_total_amount'] ?? 0,
                    'exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $item['exchange_rate_type_code'] ?? null, $item['exchange_rate_type_id'] ?? null),
                    'exchange_rate_type_code' => $item['exchange_rate_type_code'] ?? null,
                    'exchange_rate' => $item['exchange_rate'] ?? null,
                    'stock_movement_id' => null,
                    'product_unit_ids' => isset($item['product_unit_ids']) ? json_encode($item['product_unit_ids']) : null,
                    'discount_type' => $item['discount_type'] ?? null,
                    'discount_value' => $item['discount_value'] ?? 0,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'discount_base_amount' => $item['discount_base_amount'] ?? 0,
                    'discount_local_amount' => $item['discount_local_amount'] ?? 0,
                    'discount_reason' => $item['discount_reason'] ?? null,
                    'warranty_policy_id' => null,
                    'warranty_policy_name' => $item['warranty_policy_name'] ?? null,
                    'warranty_duration_days' => $item['warranty_duration_days'] ?? null,
                    'warranty_coverage_type' => $item['warranty_coverage_type'] ?? null,
                    'warranty_conditions' => $item['warranty_conditions'] ?? null,
                    'warranty_starts_at' => $this->nullableDate($item['warranty_starts_at'] ?? null),
                    'warranty_expires_at' => $this->nullableDate($item['warranty_expires_at'] ?? null),
                    'updated_at' => $now,
                ]
            );

            if ($saleStatus === 'confirmed' && $applyStock) {
                $this->applyCloudStockOut($tenant, $product->id, $warehouse->id, (float) ($item['quantity'] ?? 0));
                $this->applyCloudSerialSold($tenant, $product->id, $warehouse->id, $item['product_serial_units'] ?? []);
            }
        }

        DB::table('sale_items')
            ->where('tenant_id', $tenant->id)
            ->where('sale_id', $saleId)
            ->where('sync_source_node_code', $sourceNodeCode)
            ->whereNotIn('sync_source_id', $sourceIds)
            ->delete();
    }

    private function syncPosReceivable(Tenant $tenant, int $saleId, string $sourceNodeCode, mixed $receivable): void
    {
        if (! is_array($receivable)) {
            return;
        }

        $accountId = $this->upsertAndGetId(
            'accounts_receivables',
            ['tenant_id' => $tenant->id, 'sale_id' => $saleId],
            $this->receivableValues(
                $receivable,
                DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $saleId)->value('customer_id')
            )
        );

        $this->syncReceivablePayments($tenant, $accountId, $sourceNodeCode, $receivable['payments'] ?? []);
    }

    private function applyReceivablePayment(Tenant $tenant, array $payload, array $event): string
    {
        $sourceNodeCode = $this->sourceNodeCode($tenant, $event, $payload);
        $sourceSaleId = (int) ($payload['sale_id'] ?? 0);
        $receivable = $payload['receivable'] ?? null;
        $payment = $payload['payment'] ?? null;

        if ($sourceSaleId <= 0 || ! is_array($receivable) || ! is_array($payment)) {
            return 'ignored';
        }

        $saleId = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->where('sync_source_node_code', $sourceNodeCode)
            ->where('sync_source_id', $sourceSaleId)
            ->value('id');

        if (! $saleId) {
            throw new RuntimeException("No se encontro la venta sincronizada {$sourceSaleId} para aplicar el cobro.");
        }

        $accountId = $this->upsertAndGetId(
            'accounts_receivables',
            ['tenant_id' => $tenant->id, 'sale_id' => (int) $saleId],
            $this->receivableValues(
                $receivable,
                DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $saleId)->value('customer_id')
            )
        );

        $this->syncReceivablePayments($tenant, $accountId, $sourceNodeCode, [$payment]);

        return 'applied';
    }

    private function applySalesReturn(Tenant $tenant, array $payload, array $event): string
    {
        $sourceNodeCode = $this->sourceNodeCode($tenant, $event, $payload);
        $return = $payload['return'] ?? [];
        $sale = $payload['sale'] ?? [];
        $sourceReturnId = (int) ($return['id'] ?? $payload['sales_return_id'] ?? $event['aggregate_id'] ?? 0);
        $sourceSaleId = (int) ($sale['id'] ?? $payload['sale_id'] ?? 0);
        $saleSourceNodeCode = $sale['source_node_code'] ?? $sourceNodeCode;

        if ($sourceReturnId <= 0 || $sourceSaleId <= 0 || ! is_string($saleSourceNodeCode) || $saleSourceNodeCode === '') {
            return 'ignored';
        }

        $saleId = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->where('sync_source_node_code', $saleSourceNodeCode)
            ->where('sync_source_id', $sourceSaleId)
            ->value('id');

        if (! $saleId) {
            throw new RuntimeException("No se encontro la venta sincronizada {$sourceSaleId} para aplicar la devolucion.");
        }

        $status = $return['status'] ?? SalesReturn::STATUS_REQUESTED;
        if (! in_array($status, [
            SalesReturn::STATUS_REQUESTED,
            SalesReturn::STATUS_APPROVED,
            SalesReturn::STATUS_REJECTED,
            SalesReturn::STATUS_PROCESSED,
            SalesReturn::STATUS_CANCELLED,
        ], true)) {
            return 'ignored';
        }

        $returnKeys = [
            'tenant_id' => $tenant->id,
            'sync_source_node_code' => $sourceNodeCode,
            'sync_source_id' => $sourceReturnId,
        ];
        $previousStatus = DB::table('sales_returns')->where($returnKeys)->value('status');
        $returnId = $this->upsertAndGetId('sales_returns', $returnKeys, [
            'sale_id' => (int) $saleId,
            'status' => $status,
            'reason' => $return['reason'] ?? null,
            'created_by' => null,
            'reviewed_by' => null,
            'reviewed_at' => $this->nullableDate($return['reviewed_at'] ?? null),
            'rejection_reason' => $return['rejection_reason'] ?? null,
            'processed_by' => null,
            'processed_at' => $this->nullableDate($return['processed_at'] ?? null),
            'cancelled_by' => null,
            'cancelled_at' => $this->nullableDate($return['cancelled_at'] ?? null),
            'cancellation_reason' => $return['cancellation_reason'] ?? null,
            'process_notes' => $return['process_notes'] ?? null,
            'updated_at' => now(),
        ]);

        foreach ($payload['items'] ?? [] as $item) {
            $sourceItemId = (int) ($item['id'] ?? $item['sales_return_item_id'] ?? 0);
            $sourceSaleItemId = (int) ($item['sale_item_id'] ?? 0);
            $saleItemSourceNodeCode = $item['sale_item_source_node_code'] ?? $saleSourceNodeCode;

            if ($sourceItemId <= 0 || $sourceSaleItemId <= 0 || ! is_string($saleItemSourceNodeCode) || $saleItemSourceNodeCode === '') {
                throw new RuntimeException('La devolucion sincronizada contiene un item sin referencia de venta.');
            }

            $saleItem = DB::table('sale_items')
                ->where('tenant_id', $tenant->id)
                ->where('sync_source_node_code', $saleItemSourceNodeCode)
                ->where('sync_source_id', $sourceSaleItemId)
                ->first();

            if (! $saleItem) {
                throw new RuntimeException("No se encontro el item vendido {$sourceSaleItemId} para aplicar la devolucion.");
            }

            $product = $this->productBySku($tenant, $this->requiredString($item, 'product_sku'));
            $warehouse = $this->warehouseByCode($tenant, $this->requiredString($item, 'warehouse_code'));
            $serialUnitIds = $this->localSerialUnitIds($tenant, (int) $product->id, (int) $warehouse->id, $item['product_serial_units'] ?? []);
            $returnItemKeys = [
                'tenant_id' => $tenant->id,
                'sync_source_node_code' => $sourceNodeCode,
                'sync_source_id' => $sourceItemId,
            ];
            $returnItemId = $this->upsertAndGetId('sales_return_items', $returnItemKeys, [
                'sales_return_id' => $returnId,
                'sale_item_id' => (int) $saleItem->id,
                'warehouse_id' => (int) $warehouse->id,
                'product_id' => (int) $product->id,
                'quantity' => $item['quantity'] ?? 0,
                'product_unit_ids' => $serialUnitIds === [] ? null : json_encode($serialUnitIds),
                'condition' => $item['condition'] ?? SalesReturnItem::CONDITION_SELLABLE,
                'reason' => $item['reason'] ?? null,
                'updated_at' => now(),
            ]);

            if ($status !== SalesReturn::STATUS_PROCESSED || $previousStatus === SalesReturn::STATUS_PROCESSED) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 0);
            if ($quantity <= 0.0) {
                throw new RuntimeException('La devolucion sincronizada contiene una cantidad invalida.');
            }

            $movement = ($item['condition'] ?? SalesReturnItem::CONDITION_SELLABLE) === SalesReturnItem::CONDITION_DAMAGED
                ? app(InventoryMovementService::class)->damagedSaleReturn(
                    Warehouse::query()->findOrFail($warehouse->id),
                    Product::query()->findOrFail($product->id),
                    $quantity,
                    null,
                    $item['reason'] ?? $return['reason'] ?? "Devolucion sincronizada #{$sourceReturnId}",
                    SalesReturn::class,
                    $returnId,
                    $saleItem->base_unit_cost === null ? null : (float) $saleItem->base_unit_cost,
                )
                : app(InventoryMovementService::class)->saleReturn(
                    Warehouse::query()->findOrFail($warehouse->id),
                    Product::query()->findOrFail($product->id),
                    $quantity,
                    null,
                    $item['reason'] ?? $return['reason'] ?? "Devolucion sincronizada #{$sourceReturnId}",
                    SalesReturn::class,
                    $returnId,
                    $saleItem->base_unit_cost === null ? null : (float) $saleItem->base_unit_cost,
                );

            DB::table('sales_return_items')->where('id', $returnItemId)->update([
                'stock_movement_id' => $movement->id,
                'updated_at' => now(),
            ]);
            $this->restoreCloudSerialUnits($tenant, $serialUnitIds, (int) $warehouse->id, $item['condition'] ?? SalesReturnItem::CONDITION_SELLABLE);
        }

        if ($status === SalesReturn::STATUS_PROCESSED && $previousStatus !== SalesReturn::STATUS_PROCESSED) {
            app(AccountsReceivableService::class)->applySalesReturn(SalesReturn::query()->findOrFail($returnId));
        }

        return 'applied';
    }

    private function localSerialUnitIds(Tenant $tenant, int $productId, int $warehouseId, array $serialUnits): array
    {
        if ($serialUnits === []) {
            return [];
        }

        $ids = [];
        foreach ($serialUnits as $serialUnit) {
            $serialType = $serialUnit['serial_type'] ?? null;
            $serialNumber = $serialUnit['serial_number'] ?? null;
            if (! is_string($serialType) || ! is_string($serialNumber) || $serialType === '' || $serialNumber === '') {
                throw new RuntimeException('La devolucion sincronizada contiene un IMEI o serial invalido.');
            }

            $unitId = DB::table('product_units')
                ->where('tenant_id', $tenant->id)
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->where('serial_type', $serialType)
                ->where('serial_number', $serialNumber)
                ->value('id');
            if (! $unitId) {
                throw new RuntimeException("No se encontro el IMEI o serial {$serialNumber} para aplicar la devolucion.");
            }

            $ids[] = (int) $unitId;
        }

        return $ids;
    }

    private function restoreCloudSerialUnits(Tenant $tenant, array $unitIds, int $warehouseId, string $condition): void
    {
        if ($unitIds === []) {
            return;
        }

        DB::table('product_units')
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $unitIds)
            ->update([
                'warehouse_id' => $warehouseId,
                'status' => $condition === SalesReturnItem::CONDITION_DAMAGED
                    ? ProductUnit::STATUS_DAMAGED
                    : ProductUnit::STATUS_AVAILABLE,
                'released_stock_movement_id' => null,
                'updated_at' => now(),
            ]);
    }

    private function receivableValues(array $receivable, mixed $customerId): array
    {
        return [
            'customer_id' => $customerId ? (int) $customerId : null,
            'status' => $receivable['status'] ?? 'pending',
            'document_number' => $receivable['document_number'] ?? null,
            'currency' => $receivable['currency'] ?? 'USD',
            'original_base_amount' => $receivable['original_base_amount'] ?? 0,
            'original_local_amount' => $receivable['original_local_amount'] ?? 0,
            'returned_base_amount' => $receivable['returned_base_amount'] ?? 0,
            'returned_local_amount' => $receivable['returned_local_amount'] ?? 0,
            'collected_base_amount' => $receivable['collected_base_amount'] ?? 0,
            'collected_local_amount' => $receivable['collected_local_amount'] ?? 0,
            'adjusted_base_amount' => $receivable['adjusted_base_amount'] ?? 0,
            'adjusted_local_amount' => $receivable['adjusted_local_amount'] ?? 0,
            'balance_base_amount' => $receivable['balance_base_amount'] ?? 0,
            'balance_local_amount' => $receivable['balance_local_amount'] ?? 0,
            'due_date' => $this->nullableDate($receivable['due_date'] ?? null),
            'opened_at' => $this->nullableDate($receivable['opened_at'] ?? null),
            'paid_at' => $this->nullableDate($receivable['paid_at'] ?? null),
            'updated_at' => now(),
        ];
    }

    private function syncReceivablePayments(Tenant $tenant, int $accountId, string $sourceNodeCode, array $payments): void
    {
        foreach ($payments as $payment) {
            $sourceId = (int) ($payment['id'] ?? $payment['payment_id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }

            $this->upsertByKeys(
                'accounts_receivable_payments',
                [
                    'tenant_id' => $tenant->id,
                    'sync_source_node_code' => $sourceNodeCode,
                    'sync_source_id' => $sourceId,
                ],
                [
                    'accounts_receivable_id' => $accountId,
                    'payment_currency' => $payment['payment_currency'] ?? 'USD',
                    'amount' => $payment['amount'] ?? 0,
                    'exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $payment['exchange_rate_type_code'] ?? null, $payment['exchange_rate_type_id'] ?? null),
                    'exchange_rate_type_code' => $payment['exchange_rate_type_code'] ?? null,
                    'exchange_rate' => $payment['exchange_rate'] ?? null,
                    'amount_base' => $payment['amount_base'] ?? 0,
                    'amount_local' => $payment['amount_local'] ?? 0,
                    'method' => $payment['method'] ?? null,
                    'reference' => $payment['reference'] ?? null,
                    'notes' => $payment['notes'] ?? null,
                    'created_by' => null,
                    'paid_at' => $this->nullableDate($payment['paid_at'] ?? null) ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function applyCloudStockOut(Tenant $tenant, int $productId, int $warehouseId, float $quantity): void
    {
        if ($quantity <= 0.0) {
            return;
        }

        $now = now();

        $balance = DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (! $balance || (float) $balance->quantity_available < $quantity) {
            throw new RuntimeException(sprintf(
                'Conflicto de sincronizacion: stock insuficiente para el producto %d en el almacen %d.',
                $productId,
                $warehouseId,
            ));
        }

        DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->update([
                'quantity_available' => (float) $balance->quantity_available - $quantity,
                'updated_at' => $now,
            ]);
    }

    private function applyCloudSerialSold(Tenant $tenant, int $productId, int $warehouseId, array $serialUnits): void
    {
        if ($serialUnits === []) {
            return;
        }

        foreach ($serialUnits as $serialUnit) {
            if (! isset($serialUnit['serial_type'], $serialUnit['serial_number'])) {
                continue;
            }

            $unit = DB::table('product_units')
                ->where('tenant_id', $tenant->id)
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->where('serial_type', $serialUnit['serial_type'])
                ->where('serial_number', $serialUnit['serial_number'])
                ->lockForUpdate()
                ->first();

            if (! $unit || $unit->status !== ProductUnit::STATUS_AVAILABLE) {
                throw new RuntimeException(sprintf(
                    'Conflicto de sincronizacion: el serial %s ya no esta disponible para vender.',
                    (string) $serialUnit['serial_number'],
                ));
            }

            DB::table('product_units')
                ->where('id', $unit->id)
                ->update([
                    'status' => ProductUnit::STATUS_SOLD,
                    'updated_at' => now(),
                ]);
        }
    }

    private function syncPosPayments(Tenant $tenant, int $orderId, string $sourceNodeCode, array $payments): void
    {
        if ($payments === []) {
            return;
        }

        $sourceIds = [];
        $now = now();

        foreach ($payments as $payment) {
            $sourceId = (int) ($payment['id'] ?? $payment['payment_id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }

            $sourceIds[] = $sourceId;

            $this->upsertByKeys(
                'pos_payments',
                [
                    'tenant_id' => $tenant->id,
                    'sync_source_node_code' => $sourceNodeCode,
                    'sync_source_id' => $sourceId,
                ],
                [
                    'pos_order_id' => $orderId,
                    'payment_method_id' => $this->nullablePaymentMethodIdByCode($tenant, $payment['payment_method_code'] ?? null),
                    'method' => $payment['method'] ?? 'cash',
                    'currency' => strtoupper($payment['currency'] ?? 'USD'),
                    'amount' => $payment['amount'] ?? 0,
                    'amount_base' => $payment['amount_base'] ?? 0,
                    'amount_local' => $payment['amount_local'] ?? 0,
                    'exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $payment['exchange_rate_type_code'] ?? null, $payment['exchange_rate_type_id'] ?? null),
                    'exchange_rate_type_code' => $payment['exchange_rate_type_code'] ?? null,
                    'exchange_rate' => $payment['exchange_rate'] ?? null,
                    'status' => $payment['status'] ?? 'captured',
                    'reference' => $payment['reference'] ?? null,
                    'external_provider' => $payment['external_provider'] ?? null,
                    'metadata' => isset($payment['metadata']) ? json_encode($payment['metadata']) : null,
                    'updated_at' => $now,
                ]
            );
        }

        DB::table('pos_payments')
            ->where('tenant_id', $tenant->id)
            ->where('pos_order_id', $orderId)
            ->where('sync_source_node_code', $sourceNodeCode)
            ->whereNotIn('sync_source_id', $sourceIds)
            ->delete();
    }

    private function syncPriceListPaymentMethods(Tenant $tenant, int $priceListId, ?array $paymentMethodCodes): void
    {
        if ($paymentMethodCodes === null) {
            return;
        }

        $methodIds = DB::table('payment_methods')
            ->where('tenant_id', $tenant->id)
            ->whereIn('code', array_map(fn (string $code): string => mb_strtoupper($code), $paymentMethodCodes))
            ->pluck('id')
            ->all();

        DB::table('price_list_payment_method')
            ->where('tenant_id', $tenant->id)
            ->where('price_list_id', $priceListId)
            ->delete();

        $now = now();
        foreach ($methodIds as $methodId) {
            DB::table('price_list_payment_method')->insert([
                'tenant_id' => $tenant->id,
                'price_list_id' => $priceListId,
                'payment_method_id' => $methodId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function productBySku(Tenant $tenant, string $sku): object
    {
        $product = DB::table('products')->where('tenant_id', $tenant->id)->where('sku', $sku)->first();

        if (! $product) {
            throw new RuntimeException("No se encontro el producto {$sku} para aplicar el evento.");
        }

        return $product;
    }

    private function branchByCode(Tenant $tenant, string $code): object
    {
        $branch = DB::table('branches')->where('tenant_id', $tenant->id)->where('code', mb_strtoupper($code))->first();

        if (! $branch) {
            throw new RuntimeException("No se encontro la sucursal {$code} para aplicar el evento.");
        }

        return $branch;
    }

    private function warehouseByCode(Tenant $tenant, string $code): object
    {
        $warehouse = DB::table('warehouses')->where('tenant_id', $tenant->id)->where('code', mb_strtoupper($code))->first();

        if (! $warehouse) {
            throw new RuntimeException("No se encontro el almacen {$code} para aplicar el evento.");
        }

        return $warehouse;
    }

    private function priceListByCode(Tenant $tenant, string $code): object
    {
        $priceList = DB::table('price_lists')->where('tenant_id', $tenant->id)->where('code', mb_strtoupper($code))->first();

        if (! $priceList) {
            throw new RuntimeException("No se encontro la lista de precio {$code} para aplicar el evento.");
        }

        return $priceList;
    }

    private function nullablePriceListIdByCode(Tenant $tenant, mixed $code): ?int
    {
        $code = $this->nullableString($code);

        if ($code === null) {
            return null;
        }

        return DB::table('price_lists')
            ->where('tenant_id', $tenant->id)
            ->where('code', mb_strtoupper($code))
            ->value('id');
    }

    private function nullablePaymentMethodIdByCode(Tenant $tenant, mixed $code): ?int
    {
        $code = $this->nullableString($code);

        if ($code === null) {
            return null;
        }

        return DB::table('payment_methods')
            ->where('tenant_id', $tenant->id)
            ->where('code', mb_strtoupper($code))
            ->value('id');
    }

    private function exchangeRateTypeId(Tenant $tenant, ?string $code, mixed $fallbackId): ?int
    {
        if ($code) {
            return DB::table('exchange_rate_types')
                ->where('tenant_id', $tenant->id)
                ->where('code', mb_strtoupper($code))
                ->value('id');
        }

        return $fallbackId ? (int) $fallbackId : null;
    }

    private function customerIdByDocument(Tenant $tenant, mixed $documentType, mixed $documentNumber): ?int
    {
        $documentType = $this->nullableString($documentType);
        $documentNumber = $this->nullableString($documentNumber);

        if ($documentType === null || $documentNumber === null) {
            return null;
        }

        return DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->where('document_type', mb_strtoupper($documentType))
            ->where('document_number', $documentNumber)
            ->value('id');
    }

    private function sourceNodeCode(Tenant $tenant, array $event, array $payload): string
    {
        $payloadCode = $this->nullableString($payload['source_node_code'] ?? null);

        if ($payloadCode !== null) {
            return mb_strtoupper($payloadCode);
        }

        $originNodeId = (int) ($event['origin_node_id'] ?? 0);

        if ($originNodeId > 0) {
            $code = DB::table('sync_nodes')
                ->where('tenant_id', $tenant->id)
                ->where('id', $originNodeId)
                ->value('code');

            if ($code) {
                return mb_strtoupper((string) $code);
            }
        }

        return 'SYNC-ORIGEN-DESCONOCIDO';
    }

    private function nullableDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function warrantyPolicyId(Tenant $tenant, array $payload): ?int
    {
        $name = trim((string) ($payload['warranty_policy_name'] ?? ''));
        $fallbackId = $payload['warranty_policy_id'] ?? null;

        if ($name === '') {
            if (! $fallbackId) {
                return null;
            }

            $localId = DB::table('warranty_policies')
                ->where('tenant_id', $tenant->id)
                ->where('id', (int) $fallbackId)
                ->value('id');

            return $localId ? (int) $localId : null;
        }

        $now = now();
        $fields = [
            'duration_days' => (int) ($payload['warranty_policy_duration_days'] ?? 0),
            'coverage_type' => $payload['warranty_policy_coverage_type'] ?? 'store',
            'conditions' => $payload['warranty_policy_conditions'] ?? null,
            'is_active' => array_key_exists('warranty_policy_is_active', $payload) ? (bool) $payload['warranty_policy_is_active'] : true,
            'updated_at' => $now,
        ];

        $existing = DB::table('warranty_policies')
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->first();

        if ($existing) {
            DB::table('warranty_policies')
                ->where('tenant_id', $tenant->id)
                ->where('id', $existing->id)
                ->update($fields);

            return (int) $existing->id;
        }

        return (int) DB::table('warranty_policies')->insertGetId(array_merge($fields, [
            'tenant_id' => $tenant->id,
            'name' => $name,
            'created_at' => $now,
        ]));
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        if ($value === '') {
            throw new RuntimeException("El evento de sincronizacion no incluye {$key}.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function nullableLowerString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : mb_strtolower($value);
    }

    private function upsertByKeys(string $table, array $keys, array $values): void
    {
        $exists = DB::table($table)->where($keys)->exists();

        if ($exists) {
            DB::table($table)->where($keys)->update($values);

            return;
        }

        DB::table($table)->insert(array_merge($keys, $values, [
            'created_at' => now(),
        ]));
    }

    private function upsertAndGetId(string $table, array $keys, array $values): int
    {
        $this->upsertByKeys($table, $keys, $values);

        return (int) DB::table($table)->where($keys)->value('id');
    }

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        return json_decode($payload, true) ?: [];
    }

    private function assertPayloadIntegrity(array $event): void
    {
        $expectedHash = $event['payload_hash'] ?? null;

        if ($expectedHash === null || $expectedHash === '') {
            return;
        }

        $rawPayload = $event['payload'] ?? '';

        if (is_array($rawPayload)) {
            $rawPayload = json_encode($rawPayload);
        }

        $actualHash = hash('sha256', (string) $rawPayload);

        if (! hash_equals((string) $expectedHash, $actualHash)) {
            throw new RuntimeException(sprintf(
                'Payload hash mismatch for event %s (uuid: %s). The event may have been tampered with during transit.',
                $event['event_type'] ?? 'unknown',
                $event['event_uuid'] ?? 'unknown'
            ));
        }
    }

    private function recordProductAudit(int $productId, array $before, array $after): void
    {
        if (! Schema::hasTable('product_audits')) {
            return;
        }

        ProductAudit::create([
            'product_id' => $productId,
            'action' => ProductAudit::ACTION_UPDATED,
            'changes' => [
                'before' => $before,
                'after' => $after,
                'source' => 'sync',
            ],
            'created_by' => null,
        ]);
    }
}
