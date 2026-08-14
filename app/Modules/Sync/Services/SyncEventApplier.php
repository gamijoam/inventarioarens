<?php

namespace App\Modules\Sync\Services;

use App\Models\User;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAudit;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductImageVariant;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
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
use Spatie\Permission\Models\Role;

class SyncEventApplier
{
    public const RETRYABLE_FAILED_EVENT_TYPES = [
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
        'promotion.updated',
        'promotion.created',
        'commission_plan.updated',
        'commission_plan.created',
        'commission_entry.created',
        'commission_entries.approved',
        'commission_settlement.created',
        'product_price.updated',
        'product_price.created',
        'price.updated',
        'warranty_policy.updated',
        'warranty_policy.created',
        'supplier.updated',
        'supplier.created',
        'brand.updated',
        'brand.created',
        'category.updated',
        'category.created',
        'tag.updated',
        'tag.created',
        'payment_method.updated',
        'payment_method.created',
        'exchange_rate_type.updated',
        'exchange_rate_type.created',
        'exchange_rate.updated',
        'exchange_rate.created',
        'cash_register.updated',
        'cash_register.created',
        'product_entry.created',
        'product_exit.created',
        'purchase_order.created',
        'purchase_order.received',
        'accounts_payable.created',
        'accounts_payable.updated',
        'accounts_payable.payment_registered',
        'accounts_receivable.created',
        'accounts_receivable.updated',
        'sale.confirmed',
        'user.roles.synced',
        'purchase_return.created',
        'cash.session.opened',
        'cash.session.closed',
    ];

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
        'promotion.updated',
        'promotion.created',
        'promotion.deleted',
        'commission_plan.updated',
        'commission_plan.created',
        'commission_entry.created',
        'commission_entries.approved',
        'commission_settlement.created',
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
        'accounts_payable.created',
        'accounts_payable.updated',
        'accounts_payable.payment_registered',
        'accounts_receivable.created',
        'accounts_receivable.updated',
        'sale.confirmed',
        'user.roles.synced',
        'purchase_return.created',
        'cash.session.opened',
        'cash.session.closed',
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
                    })
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', 'failed')
                            ->whereIn('event_type', self::RETRYABLE_FAILED_EVENT_TYPES);
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
        $payload['_sync_aggregate_id'] = isset($event['aggregate_id']) ? (int) $event['aggregate_id'] : null;

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
                'promotion.updated', 'promotion.created', 'promotion.deleted' => $this->applyPromotion($tenant, $payload),
                'commission_plan.updated', 'commission_plan.created' => $this->applyCommissionPlan($tenant, $payload),
                'commission_entry.created' => $this->applyCommissionEntry($tenant, $payload, $event),
                'commission_entries.approved' => $this->applyCommissionApproval($tenant, $payload),
                'commission_settlement.created' => $this->applyCommissionSettlement($tenant, $payload),
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
                'cash.session.opened', 'cash.session.closed' => $this->applyCashSession($tenant, $payload, $event),
                'inventory_transfer.updated', 'inventory_transfer.created' => $this->applyInventoryTransfer($tenant, $payload),
                'inventory_transfer_request.created' => $this->applyInventoryTransferRequestCreated($tenant, $payload),
                'inventory_transfer_request.accepted' => $this->applyInventoryTransferRequestAccepted($tenant, $payload),
                'inventory_transfer_request.rejected' => $this->applyInventoryTransferRequestRejected($tenant, $payload),
                'inventory_transfer_request.cancelled' => $this->applyInventoryTransferRequestCancelled($tenant, $payload),
                'pos.order.pending', 'pos.order.payment_added', 'pos.order.paid', 'pos.order.cancelled' => $this->applyPosOrder($tenant, $payload, $event),
                'accounts_receivable.payment_registered' => $this->applyReceivablePayment($tenant, $payload, $event),
                'sales_return.updated' => $this->applySalesReturn($tenant, $payload, $event),
                'accounts_payable.created', 'accounts_payable.updated' => $this->applyAccountsPayable($tenant, $payload, $event),
                'accounts_payable.payment_registered' => $this->applyPayablePayment($tenant, $payload, $event),
                'accounts_receivable.created', 'accounts_receivable.updated' => $this->applyAccountsReceivable($tenant, $payload, $event),
                'sale.confirmed' => $this->applySale($tenant, $payload, $event),
                'user.roles.synced' => $this->applyUserRoles($tenant, $payload),
                'purchase_return.created' => $this->applyPurchaseReturn($tenant, $payload, $event),
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

        $remoteId = (int) ($payload['_sync_aggregate_id'] ?? 0);
        if ($remoteId > 0) {
            $this->rememberEntityMapping($tenant, 'branch', $remoteId, (int) DB::table('branches')
                ->where('tenant_id', $tenant->id)
                ->where('code', $code)
                ->value('id'), $code);
        }

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

        $remoteId = (int) ($payload['_sync_aggregate_id'] ?? 0);
        if ($remoteId > 0) {
            $this->rememberEntityMapping($tenant, 'warehouse', $remoteId, (int) DB::table('warehouses')
                ->where('tenant_id', $tenant->id)
                ->where('code', $code)
                ->value('id'), $code);
        }

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
            'catalog_product_id' => isset($payload['catalog_product_id']) ? (int) $payload['catalog_product_id'] : null,
            'updated_at' => $now,
        ];

        $product = DB::table('products')->where('tenant_id', $tenant->id)
            ->when(isset($payload['catalog_product_id']), fn ($query) => $query->where('catalog_product_id', (int) $payload['catalog_product_id']))
            ->when(! isset($payload['catalog_product_id']), fn ($query) => $query->where('sku', $sku))
            ->first();

        // Fallback por SKU: el catalog_product_id del evento es el id REMOTO
        // del master, que NO coincide con el id local del spinoff (guardado al
        // propagar). Sin este fallback, el applier intentaba insertar y rompia
        // el UNIQUE(tenant_id, sku) cuando la copia ya existia.
        if (! $product) {
            $product = DB::table('products')->where('tenant_id', $tenant->id)->where('sku', $sku)->first();
        }
        $before = $product ? (array) $product : [];

        if ($product) {
            DB::table('products')->where('tenant_id', $tenant->id)->where('id', $product->id)->update($fields);
            $productId = (int) $product->id;
        } else {
            $productId = (int) DB::table('products')->insertGetId(array_merge($fields, [
                'tenant_id' => $tenant->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $after = (array) DB::table('products')->where('tenant_id', $tenant->id)->where('id', $productId)->first();
        $this->syncProductCatalogRelations($tenant, $productId, $payload);
        $this->recordProductAudit($productId, $before, $after);

        $remoteId = (int) ($payload['_sync_aggregate_id'] ?? 0);
        if ($remoteId > 0) {
            $this->rememberEntityMapping($tenant, 'product', $remoteId, $productId, $sku);
        }

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

        $existing = DB::table('stock_movements')
            ->where($keys)
            ->value('id');

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

        // Si el movimiento ya existia (re-proceso), no volver a aplicar el efecto
        // al stock (evita duplicar saldos).
        if ($existing) {
            return 'applied';
        }

        $this->applyStockMovementToBalance(
            tenant: $tenant,
            warehouseId: (int) $warehouse->id,
            productId: (int) $product->id,
            variantId: $this->variantIdBySku($tenant, (int) $product->id, $payload['product_variant_sku'] ?? null, $payload['product_variant_color'] ?? null),
            type: (string) ($payload['type'] ?? 'adjustment'),
            quantity: (float) ($payload['quantity'] ?? 0),
        );

        return 'applied';
    }

    /**
     * Aplica el efecto neto de un stock_movement sobre stock_balances.
     * Las entradas (purchase, sale_return, adjustment_in, transfer_in, return_in)
     * suman; las salidas (sale, purchase_return, adjustment_out, transfer_out,
     * return_out, damaged, reserved) restan. Los tipos neutros (released) no
     * afectan el disponible.
     */
    private function applyStockMovementToBalance(
        Tenant $tenant,
        int $warehouseId,
        int $productId,
        ?int $variantId,
        string $type,
        float $quantity,
    ): void {
        if ($quantity <= 0.0) {
            return;
        }

        $sign = match ($type) {
            'purchase', 'sale_return', 'adjustment_in', 'transfer_in',
            'transfer_request_in', 'return_in' => 1,
            'sale', 'purchase_return', 'adjustment_out', 'transfer_out',
            'transfer_request_out', 'return_out', 'damaged', 'reserved' => -1,
            default => 0,
        };

        if ($sign === 0) {
            return;
        }

        $balance = DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        $delta = $sign * $quantity;

        if ($balance) {
            DB::table('stock_balances')
                ->where('id', $balance->id)
                ->update([
                    'quantity_available' => max(0.0, (float) $balance->quantity_available + $delta),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('stock_balances')->insert([
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity_available' => max(0.0, $delta),
                'quantity_reserved' => 0,
                'quantity_damaged' => 0,
            ]);
        }
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
                    productVariantSku: $item['product_variant_sku'] ?? null,
                    productVariantColor: $item['product_variant_color'] ?? null,
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

        $po = DB::table('purchase_orders')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', $documentNumber)
            ->first();

        // Replicar los items del draft para que la UI de la nube muestre
        // los productos y costos de la orden pendiente. El evento trae
        // sku/warehouse_code (identidad natural entre nodos); resolvemos
        // los IDs locales y creamos los purchase_items con received_quantity=0.
        foreach ($payload['items'] ?? [] as $item) {
            $sku = $this->nullableString($item['sku'] ?? null);
            $warehouseCode = $this->nullableString($item['warehouse_code'] ?? null);

            if ($sku === null || $warehouseCode === null) {
                continue;
            }

            try {
                $product = $this->productBySku($tenant, $sku);
                $warehouse = $this->warehouseByCode($tenant, $warehouseCode);
            } catch (RuntimeException) {
                // Si el catalogo aun no llego (producto o almacen desconocido),
                // omitimos ese item: el PO existe y se completa al recibir.
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 0);
            $unitCost = $this->nullableString($item['unit_cost'] ?? null) !== null
                ? (float) $item['unit_cost']
                : null;
            $baseUnitCost = $this->nullableString($item['base_unit_cost'] ?? null) !== null
                ? (float) $item['base_unit_cost']
                : null;
            $variantId = $this->variantIdBySku($tenant, $product->id, $item['product_variant_sku'] ?? null, $item['product_variant_color'] ?? null);

            DB::table('purchase_items')->insert([
                'tenant_id' => $tenant->id,
                'purchase_order_id' => $po->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
                'received_quantity' => 0,
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost === null ? null : round($unitCost * $quantity, 4),
                'base_unit_cost' => $baseUnitCost,
                'base_total_cost' => $baseUnitCost === null ? null : round($baseUnitCost * $quantity, 4),
                'serial_units' => null,
                'stock_movement_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return 'applied';
    }

    private function variantIdBySku(Tenant $tenant, int $productId, mixed $variantSku, mixed $variantColor = null): ?int
    {
        $variantSku = $this->nullableString($variantSku);
        $variantColor = $this->nullableString($variantColor);

        $query = DB::table('product_variants')
            ->where('tenant_id', $tenant->id)
            ->where('product_id', $productId);

        if ($variantSku !== null) {
            $query->where('sku_variant', $variantSku);
        } elseif ($variantColor !== null) {
            $query->where('color', $variantColor);
        } else {
            return null;
        }

        return $query->value('id');
    }

    /**
     * Aplica un `purchase_order.received` en la nube. Convierte la orden de
     * compra local en una entrada de stock (`product_entries` + items +
     * stock_movements) manteniendo el `document_number` original del PO.
     * Esto preserva la trazabilidad de la fuente (la compra) y mantiene
     * el stock sincronizado entre local y nube.
     *
     * Ademas de crear la entrada de stock, marca el `purchase_orders`
     * existente como `received`/`partially_received` y actualiza los
     * `purchase_items` con la cantidad recibida. Sin esto, la UI de la nube
     * mostraria la compra como pendiente de recibir (boton "Recibir
     * mercancia") aunque el stock ya entro, arriesgando una recepcion
     * duplicada.
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

        $this->applyProductEntry($tenant, $mapped);
        $this->markPurchaseOrderReceived($tenant, $payload);

        return 'applied';
    }

    /**
     * Marca el purchase_order existente como recibido en la nube y actualiza
     * los purchase_items con las cantidades recibidas. El evento trae
     * sku/warehouse_code (identidad natural); resolvemos los items por
     * (product_id, product_variant_id) dentro del PO.
     */
    private function markPurchaseOrderReceived(Tenant $tenant, array $payload): void
    {
        $documentNumber = $this->requiredString($payload, 'document_number');
        $po = DB::table('purchase_orders')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', $documentNumber)
            ->first();

        if (! $po) {
            return;
        }

        $receivedItems = 0;
        $receivedBase = 0.0;

        foreach ($payload['items'] ?? [] as $item) {
            $sku = $this->nullableString($item['sku'] ?? null);
            if ($sku === null) {
                continue;
            }

            $product = $this->productBySku($tenant, $sku);
            $variantId = $this->variantIdBySku($tenant, (int) $product->id, $item['product_variant_sku'] ?? null, $item['product_variant_color'] ?? null);
            $quantity = (float) ($item['quantity'] ?? 0);

            $query = DB::table('purchase_items')
                ->where('tenant_id', $tenant->id)
                ->where('purchase_order_id', $po->id)
                ->where('product_id', $product->id);

            if ($variantId !== null) {
                $query->where('product_variant_id', $variantId);
            }

            $pi = $query->first();

            if (! $pi) {
                continue;
            }

            DB::table('purchase_items')
                ->where('id', $pi->id)
                ->update([
                    'received_quantity' => $quantity,
                    'updated_at' => now(),
                ]);

            $receivedItems++;
            $receivedBase += round((float) $pi->base_unit_cost * $quantity, 4);
        }

        $totalItems = DB::table('purchase_items')
            ->where('tenant_id', $tenant->id)
            ->where('purchase_order_id', $po->id)
            ->count();

        $allReceived = $receivedItems > 0 && $totalItems > 0 && $receivedItems >= $totalItems;

        DB::table('purchase_orders')
            ->where('id', $po->id)
            ->update([
                'status' => $allReceived ? 'received' : 'partially_received',
                'received_base_amount' => $receivedBase,
                'received_at' => $payload['received_at'] ?? now(),
                'updated_at' => now(),
            ]);
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
                    productVariantSku: $item['product_variant_sku'] ?? null,
                    productVariantColor: $item['product_variant_color'] ?? null,
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
        mixed $productVariantSku = null,
        mixed $productVariantColor = null,
    ): void {
        if ($quantity <= 0.0) {
            return;
        }

        $product = $this->productBySku($tenant, $productSku);
        $warehouse = $this->warehouseByCode($tenant, $warehouseCode);
        $normalizedSerialUnits = $this->normalizeSerialUnits($serialUnits);
        $variantId = $this->variantIdBySku($tenant, (int) $product->id, $productVariantSku, $productVariantColor);

        $stockBalance = DB::table('stock_balances')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if ($stockBalance) {
            $newQuantity = (float) $stockBalance->quantity_available + ($documentType === 'entry' ? $quantity : -$quantity);
            DB::table('stock_balances')
                ->where('tenant_id', $tenant->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variantId)
                ->update([
                    'quantity_available' => $newQuantity,
                ]);
        } else {
            DB::table('stock_balances')->insert([
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'quantity_available' => $documentType === 'entry' ? $quantity : -$quantity,
                'quantity_reserved' => 0,
                'quantity_damaged' => 0,
            ]);
        }

        $movementId = (int) DB::table('stock_movements')->insertGetId([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
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
                'product_variant_id' => $variantId,
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
                productVariantId: $variantId,
            );
        } else {
            DB::table('product_exit_items')->insert([
                'tenant_id' => $tenant->id,
                'product_exit_id' => $productExitId,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
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
        ?int $productVariantId = null,
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
                    'product_variant_id' => $productVariantId,
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
        $originTenantId = $this->localTenantIdForRemote((int) $payload['origin_tenant_id']);
        $sequence = (int) $payload['sequence'];

        $existing = DB::table('inventory_transfer_requests')
            ->where('origin_tenant_id', $originTenantId)
            ->where('sequence', $sequence)
            ->first();

        if ($existing && $existing->status === 'completed') {
            return 'applied';
        }

        return DB::transaction(function () use ($payload, $originTenantId): string {
            $this->upsertTransferRequest($payload, [
                'status' => 'completed',
                'response_notes' => $payload['response_notes'] ?? null,
                'responded_by' => $payload['responded_by'] ?? null,
                'responded_at' => isset($payload['responded_at']) ? Carbon::parse($payload['responded_at']) : now(),
                'completed_at' => isset($payload['completed_at']) ? Carbon::parse($payload['completed_at']) : now(),
            ]);

            $requestId = (int) DB::table('inventory_transfer_requests')
                ->where('origin_tenant_id', $originTenantId)
                ->where('sequence', (int) $payload['sequence'])
                ->value('id');

            $items = $payload['items'] ?? [];
            $flowType = $payload['flow_type'] ?? 'stock_request';
            $senderRemoteTenantId = (int) ($payload['sender_tenant_id'] ?? $payload['destination_tenant_id']);
            $receiverRemoteTenantId = (int) ($payload['receiver_tenant_id'] ?? $payload['origin_tenant_id']);
            $senderTenantId = $this->localTenantIdForRemote($senderRemoteTenantId);
            $receiverTenantId = $this->localTenantIdForRemote($receiverRemoteTenantId);
            $senderWarehouseId = $this->localEntityId(
                'warehouse',
                $senderRemoteTenantId,
                (int) ($payload['sender_warehouse_id'] ?? $payload['destination_warehouse_id'] ?? 0),
            );
            $receiverWarehouseId = $this->localEntityId(
                'warehouse',
                $receiverRemoteTenantId,
                (int) ($payload['receiver_warehouse_id'] ?? $payload['from_warehouse_id']),
            );
            foreach ($items as $itemPayload) {
                $itemPayload['origin_product_id'] = $this->localEntityId(
                    'product',
                    (int) $payload['origin_tenant_id'],
                    (int) ($itemPayload['origin_product_id'] ?? 0),
                );
                $itemPayload['destination_product_id'] = isset($itemPayload['destination_product_id'])
                    ? $this->localEntityId(
                        'product',
                        (int) ($payload['destination_tenant_id'] ?? 0),
                        (int) $itemPayload['destination_product_id'],
                    )
                    : null;
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
        $originTenantId = $this->localTenantIdForRemote((int) $payload['origin_tenant_id']);
        $sequence = (int) $payload['sequence'];

        if ($originTenantId <= 0 || $sequence <= 0) {
            return 'ignored';
        }

        $now = now();
        $base = [
            'document_number' => $payload['document_number'] ?? null,
            'origin_tenant_id' => $originTenantId,
            'destination_tenant_id' => $this->localTenantIdForRemote((int) ($payload['destination_tenant_id'] ?? 0)),
            'flow_type' => $payload['flow_type'] ?? 'stock_request',
            'initiated_by_tenant_id' => $this->localTenantIdForRemote((int) ($payload['initiated_by_tenant_id'] ?? $payload['origin_tenant_id'])),
            'sender_tenant_id' => $this->localTenantIdForRemote((int) ($payload['sender_tenant_id'] ?? ($payload['destination_tenant_id'] ?? 0))),
            'receiver_tenant_id' => $this->localTenantIdForRemote((int) ($payload['receiver_tenant_id'] ?? $payload['origin_tenant_id'])),
            'from_warehouse_id' => $this->localEntityId('warehouse', (int) $payload['origin_tenant_id'], (int) ($payload['from_warehouse_id'] ?? 0)),
            'destination_warehouse_id' => $this->localEntityId('warehouse', (int) ($payload['destination_tenant_id'] ?? 0), (int) ($payload['destination_warehouse_id'] ?? 0)),
            'sender_warehouse_id' => $this->localEntityId('warehouse', (int) ($payload['sender_tenant_id'] ?? ($payload['destination_tenant_id'] ?? 0)), (int) ($payload['sender_warehouse_id'] ?? ($payload['destination_warehouse_id'] ?? 0))),
            'receiver_warehouse_id' => $this->localEntityId('warehouse', (int) ($payload['receiver_tenant_id'] ?? $payload['origin_tenant_id']), (int) ($payload['receiver_warehouse_id'] ?? ($payload['from_warehouse_id'] ?? 0))),
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
                    'origin_product_id' => $this->localEntityId('product', (int) $payload['origin_tenant_id'], (int) ($itemPayload['origin_product_id'] ?? 0)),
                    'destination_product_id' => isset($itemPayload['destination_product_id'])
                        ? $this->localEntityId('product', (int) ($payload['destination_tenant_id'] ?? 0), (int) $itemPayload['destination_product_id'])
                        : null,
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
                'payment_exchange_rate_type_id' => $this->exchangeRateTypeId(
                    $tenant,
                    $payload['payment_exchange_rate_type_code'] ?? null,
                    null,
                ),
                'updated_at' => $now,
            ]
        );

        $priceList = DB::table('price_lists')->where('tenant_id', $tenant->id)->where('code', $code)->first();
        $this->syncPriceListPaymentMethods($tenant, (int) $priceList->id, $payload['payment_method_codes'] ?? null);

        return 'applied';
    }

    private function applyPromotion(Tenant $tenant, array $payload): string
    {
        $code = isset($payload['code']) && trim((string) $payload['code']) !== ''
            ? mb_strtoupper(trim((string) $payload['code']))
            : null;
        $promotion = $code
            ? Promotion::query()->where('code', $code)->first()
            : (isset($payload['id']) ? Promotion::query()->find((int) $payload['id']) : null);

        if (($payload['_deleted'] ?? false) === true) {
            $promotion?->update(['is_active' => false]);

            return 'applied';
        }

        $items = $payload['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new RuntimeException('La promocion sincronizada no tiene componentes.');
        }

        if (! $promotion) {
            $promotion = new Promotion;
        }

        $promotion->fill([
            'name' => $this->requiredString($payload, 'name'),
            'code' => $code,
            'benefit_type' => $this->requiredString($payload, 'benefit_type'),
            'price_currency' => strtoupper((string) ($payload['price_currency'] ?? 'USD')),
            'price_usd' => $payload['price_usd'] ?? null,
            'discount_percent' => $payload['discount_percent'] ?? null,
            'discount_amount_usd' => $payload['discount_amount_usd'] ?? null,
            'priority' => (int) ($payload['priority'] ?? 0),
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
        ]);
        $promotion->save();
        $promotion->items()->delete();

        foreach ($items as $item) {
            $product = $this->productBySku($tenant, $this->requiredString($item, 'product_sku'));
            $promotion->items()->create([
                'product_id' => $product->id,
                'quantity' => $item['quantity'] ?? 1,
                'item_role' => $item['item_role'] ?? 'eligible',
                'sort_order' => (int) ($item['sort_order'] ?? 0),
            ]);
        }

        return 'applied';
    }

    private function applyCommissionPlan(Tenant $tenant, array $payload): string
    {
        $name = $this->requiredString($payload, 'name');
        $assignments = $payload['assignments'] ?? [];
        if (! is_array($assignments) || $assignments === []) {
            throw new RuntimeException('El plan de comisiones sincronizado no tiene personas asignadas.');
        }

        $resolvedAssignments = [];
        foreach ($assignments as $assignment) {
            $email = mb_strtolower($this->requiredString($assignment, 'user_email'));
            $userId = DB::table('users')
                ->join('tenant_user', 'tenant_user.user_id', '=', 'users.id')
                ->where('tenant_user.tenant_id', $tenant->id)
                ->where('tenant_user.status', 'active')
                ->whereRaw('LOWER(users.email) = ?', [$email])
                ->value('users.id');

            if (! $userId) {
                throw new RuntimeException("No existe un usuario local activo para la comision: {$email}.");
            }
            $resolvedAssignments[] = [(int) $userId, $assignment];
        }

        $now = now();
        $planId = $this->upsertAndGetId(
            'commission_plans',
            ['tenant_id' => $tenant->id, 'name' => $name],
            [
                'beneficiary_role' => $this->requiredString($payload, 'beneficiary_role'),
                'percentage' => $payload['percentage'],
                'conversion_policy' => $payload['conversion_policy'] ?? 'sale_snapshot',
                'exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $payload['exchange_rate_type_code'] ?? null, null),
                'credit_policy' => $payload['credit_policy'] ?? 'proportional_collections',
                'maturation_days' => (int) ($payload['maturation_days'] ?? 0),
                'allow_self_stacking' => (bool) ($payload['allow_self_stacking'] ?? false),
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
                'created_at' => $payload['created_at'] ?? $now,
                'updated_at' => $payload['updated_at'] ?? $now,
            ]
        );

        DB::table('commission_plan_assignments')
            ->where('tenant_id', $tenant->id)
            ->where('commission_plan_id', $planId)
            ->delete();

        foreach ($resolvedAssignments as [$userId, $assignment]) {
            DB::table('commission_plan_assignments')->insert([
                'tenant_id' => $tenant->id,
                'commission_plan_id' => $planId,
                'user_id' => $userId,
                'is_active' => array_key_exists('is_active', $assignment) ? (bool) $assignment['is_active'] : true,
                'starts_at' => $assignment['starts_at'] ?? null,
                'ends_at' => $assignment['ends_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return 'applied';
    }

    private function applyCommissionEntry(Tenant $tenant, array $payload, array $event): string
    {
        $entryUuid = $this->requiredString($payload, 'entry_uuid');
        $entryType = $payload['entry_type'] ?? 'earning';
        $saleId = null;
        $saleItemId = null;
        if ($entryType !== 'adjustment') {
            $sourceNodeCode = $this->sourceNodeCode($tenant, $event, $payload);
            $saleSourceId = (int) ($payload['sale_id'] ?? 0);
            $itemSourceId = (int) ($payload['sale_item_id'] ?? 0);
            $saleId = DB::table('sales')
                ->where('tenant_id', $tenant->id)
                ->where('sync_source_node_code', $sourceNodeCode)
                ->where('sync_source_id', $saleSourceId)
                ->value('id');
            $saleItemId = DB::table('sale_items')
                ->where('tenant_id', $tenant->id)
                ->where('sync_source_node_code', $sourceNodeCode)
                ->where('sync_source_id', $itemSourceId)
                ->value('id');
            if (! $saleId || ! $saleItemId) {
                throw new RuntimeException('La venta de la comision aun no existe en este nodo.');
            }
        }

        $email = $this->requiredString($payload, 'beneficiary_email');
        $userId = $this->activeTenantUserIdByEmail($tenant, $email);
        if (! $userId) {
            throw new RuntimeException("No existe el beneficiario local de la comision: {$email}.");
        }

        $planName = $this->requiredString($payload, 'plan_name_snapshot');
        $planId = $entryType === 'adjustment' ? null : DB::table('commission_plans')
            ->where('tenant_id', $tenant->id)
            ->where('name', $planName)
            ->value('id');
        $originalEntryId = null;
        if (! empty($payload['original_entry_uuid'])) {
            $originalEntryId = DB::table('commission_entries')
                ->where('tenant_id', $tenant->id)
                ->where('entry_uuid', $payload['original_entry_uuid'])
                ->value('id');
            if (! $originalEntryId) {
                throw new RuntimeException('La comision original del reverso aun no existe en este nodo.');
            }
        }

        $now = now();
        $this->upsertByKeys(
            'commission_entries',
            ['tenant_id' => $tenant->id, 'entry_uuid' => $entryUuid],
            [
                'commission_plan_id' => $planId,
                'sale_id' => $saleId,
                'pos_order_id' => null,
                'sale_item_id' => $saleItemId,
                'accounts_receivable_payment_id' => null,
                'sales_return_id' => null,
                'beneficiary_user_id' => $userId,
                'beneficiary_role' => $this->requiredString($payload, 'beneficiary_role'),
                'entry_type' => $entryType,
                'original_entry_id' => $originalEntryId,
                'plan_name_snapshot' => $planName,
                'percentage_snapshot' => $payload['percentage_snapshot'],
                'sale_currency' => strtoupper($payload['sale_currency'] ?? 'USD'),
                'source_amount' => $payload['source_amount'],
                'eligible_base_amount' => $payload['eligible_base_amount'],
                'exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $payload['exchange_rate_type_code'] ?? null, null),
                'exchange_rate_type_code' => $payload['exchange_rate_type_code'] ?? null,
                'exchange_rate' => $payload['exchange_rate'] ?? null,
                'commission_base_amount' => $payload['commission_base_amount'],
                'status' => $payload['status'] ?? 'pending',
                'adjustment_reason' => $payload['adjustment_reason'] ?? null,
                'approved_at' => $payload['approved_at'] ?? null,
                'earned_at' => $payload['earned_at'] ?? $now,
                'available_at' => $payload['available_at'] ?? null,
                'created_at' => $payload['created_at'] ?? $now,
                'updated_at' => $payload['updated_at'] ?? $now,
            ]
        );

        return 'applied';
    }

    private function applyCommissionApproval(Tenant $tenant, array $payload): string
    {
        $entryUuids = array_values(array_filter($payload['entry_uuids'] ?? [], 'is_string'));
        if ($entryUuids === []) {
            throw new RuntimeException('La aprobacion no contiene comisiones.');
        }
        $approverId = $this->activeTenantUserIdByEmail($tenant, $this->requiredString($payload, 'approved_by_email'));
        if (! $approverId) {
            throw new RuntimeException('El aprobador de comisiones aun no existe en este nodo.');
        }
        $entries = DB::table('commission_entries')
            ->where('tenant_id', $tenant->id)
            ->whereIn('entry_uuid', $entryUuids)
            ->get(['id', 'status']);
        if ($entries->count() !== count($entryUuids)) {
            throw new RuntimeException('No todas las comisiones aprobadas existen aun en este nodo.');
        }
        DB::table('commission_entries')
            ->whereIn('id', $entries->where('status', '!=', 'paid')->pluck('id'))
            ->update([
                'status' => 'approved',
                'approved_by' => $approverId,
                'approved_at' => $payload['approved_at'] ?? now(),
                'updated_at' => $payload['updated_at'] ?? now(),
            ]);

        return 'applied';
    }

    private function applyCommissionSettlement(Tenant $tenant, array $payload): string
    {
        $settlementUuid = $this->requiredString($payload, 'settlement_uuid');
        $beneficiaryId = $this->activeTenantUserIdByEmail($tenant, $this->requiredString($payload, 'beneficiary_email'));
        $payerId = $this->activeTenantUserIdByEmail($tenant, $this->requiredString($payload, 'paid_by_email'));
        if (! $beneficiaryId || ! $payerId) {
            throw new RuntimeException('Los usuarios del pago de comisiones aun no existen en este nodo.');
        }
        $entryUuids = array_values(array_filter($payload['entry_uuids'] ?? [], 'is_string'));
        $entries = DB::table('commission_entries')
            ->where('tenant_id', $tenant->id)
            ->whereIn('entry_uuid', $entryUuids)
            ->get();
        if ($entryUuids === [] || $entries->count() !== count($entryUuids)) {
            throw new RuntimeException('Las comisiones del pago aun no existen en este nodo.');
        }

        $now = now();
        $settlementId = $this->upsertAndGetId(
            'commission_settlements',
            ['tenant_id' => $tenant->id, 'settlement_uuid' => $settlementUuid],
            [
                'beneficiary_user_id' => $beneficiaryId,
                'status' => $payload['status'] ?? 'paid',
                'payment_currency' => strtoupper($payload['payment_currency'] ?? 'USD'),
                'total_base_amount' => $payload['total_base_amount'],
                'total_local_amount' => $payload['total_local_amount'],
                'payment_amount' => $payload['payment_amount'],
                'exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $payload['exchange_rate_type_code'] ?? null, null),
                'exchange_rate_type_code' => $payload['exchange_rate_type_code'] ?? null,
                'exchange_rate' => $payload['exchange_rate'] ?? null,
                'reference' => $payload['reference'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'paid_by' => $payerId,
                'paid_at' => $payload['paid_at'] ?? $now,
                'created_at' => $payload['created_at'] ?? $now,
                'updated_at' => $payload['updated_at'] ?? $now,
            ]
        );
        foreach ($entries as $entry) {
            $this->upsertByKeys(
                'commission_settlement_items',
                ['tenant_id' => $tenant->id, 'commission_entry_id' => $entry->id],
                [
                    'commission_settlement_id' => $settlementId,
                    'commission_base_amount' => $entry->commission_base_amount,
                    'created_at' => $payload['created_at'] ?? $now,
                    'updated_at' => $payload['updated_at'] ?? $now,
                ]
            );
            DB::table('commission_entries')->where('id', $entry->id)->update([
                'status' => 'paid',
                'updated_at' => $payload['updated_at'] ?? $now,
            ]);
        }

        return 'applied';
    }

    private function activeTenantUserIdByEmail(Tenant $tenant, string $email): ?int
    {
        $id = DB::table('users')
            ->join('tenant_user', 'tenant_user.user_id', '=', 'users.id')
            ->where('tenant_user.tenant_id', $tenant->id)
            ->where('tenant_user.status', 'active')
            ->whereRaw('LOWER(users.email) = ?', [mb_strtolower($email)])
            ->value('users.id');

        return $id ? (int) $id : null;
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

    /**
     * Aplica `cash.session.opened` / `cash.session.closed` en el nodo destino.
     * La sesion de caja se replica por (tenant_id, sync_source_node_code,
     * sync_source_id). Resuelve branch/cash_register por code y los users por
     * email (identidad natural).
     */
    private function applyCashSession(Tenant $tenant, array $payload, array $event): string
    {
        $sourceNodeCode = $this->sourceNodeCode($tenant, $event, $payload);
        $sourceSessionId = (int) ($payload['session_id'] ?? $event['aggregate_id'] ?? 0);

        if ($sourceSessionId <= 0) {
            return 'ignored';
        }

        $branchCode = $this->nullableString($payload['branch_code'] ?? null);
        $cashRegisterCode = $this->nullableString($payload['cash_register_code'] ?? null);

        if ($branchCode === null) {
            // Evento legacy que no incluye branch_code (payload viejo). No se
            // puede ubicar la sucursal: ignorarlo en vez de reintentar en cada
            // sync y ensuciar el log de fallos.
            return 'ignored';
        }

        $branch = $this->branchByCode($tenant, $branchCode);
        $cashRegisterId = null;

        if ($cashRegisterCode !== null) {
            $cashRegisterId = DB::table('cash_registers')
                ->where('tenant_id', $tenant->id)
                ->where('code', mb_strtoupper($cashRegisterCode))
                ->value('id');
        }

        $cashierId = $this->userIdByEmail($tenant, $payload['cashier_email'] ?? null);
        $openedById = $this->userIdByEmail($tenant, $payload['opened_by_email'] ?? null);
        $closedById = $this->userIdByEmail($tenant, $payload['closed_by_email'] ?? null);
        $reviewedById = $this->userIdByEmail($tenant, $payload['reviewed_by_email'] ?? null);

        $now = now();
        $status = $payload['status'] ?? 'open';

        $this->upsertByKeys(
            'cash_register_sessions',
            [
                'tenant_id' => $tenant->id,
                'sync_source_node_code' => $sourceNodeCode,
                'sync_source_id' => $sourceSessionId,
            ],
            [
                'branch_id' => $branch->id,
                'cash_register_id' => $cashRegisterId,
                'cashier_id' => $cashierId,
                'opened_by' => $openedById,
                'closed_by' => $closedById,
                'status' => $status,
                'opening_base_amount' => $payload['opening_base_amount'] ?? 0,
                'opening_local_amount' => $payload['opening_local_amount'] ?? 0,
                'expected_base_amount' => $payload['expected_base_amount'] ?? 0,
                'expected_local_amount' => $payload['expected_local_amount'] ?? 0,
                'expected_cash_usd' => $this->nullableString($payload['expected_cash_usd'] ?? null),
                'expected_cash_ves' => $this->nullableString($payload['expected_cash_ves'] ?? null),
                'counted_base_amount' => $this->nullableString($payload['counted_base_amount'] ?? null),
                'counted_local_amount' => $this->nullableString($payload['counted_local_amount'] ?? null),
                'counted_cash_usd' => $this->nullableString($payload['counted_cash_usd'] ?? null),
                'counted_cash_ves' => $this->nullableString($payload['counted_cash_ves'] ?? null),
                'difference_base_amount' => $this->nullableString($payload['difference_base_amount'] ?? null),
                'difference_local_amount' => $this->nullableString($payload['difference_local_amount'] ?? null),
                'difference_cash_usd' => $this->nullableString($payload['difference_cash_usd'] ?? null),
                'difference_cash_ves' => $this->nullableString($payload['difference_cash_ves'] ?? null),
                'counting_mode' => $payload['counting_mode'] ?? 'standard',
                'review_status' => $payload['review_status'] ?? 'pending',
                'reviewed_by' => $reviewedById,
                'reviewed_at' => $this->nullableString($payload['reviewed_at'] ?? null),
                'review_notes' => $this->nullableString($payload['review_notes'] ?? null),
                'opened_at' => $this->nullableDate($payload['opened_at'] ?? null) ?? $now,
                'closed_at' => $this->nullableDate($payload['closed_at'] ?? null),
                'updated_at' => $now,
            ]
        );

        return 'applied';
    }

    private function userIdByEmail(Tenant $tenant, mixed $email): ?int
    {
        $email = $this->nullableString($email);

        if ($email === null) {
            return null;
        }

        $id = DB::table('users')->where('email', mb_strtolower($email))->value('id');

        return $id ? (int) $id : null;
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
                    'promotion_id' => $item['promotion_id'] ?? null,
                    'promotion_code' => $item['promotion_code'] ?? null,
                    'promotion_name' => $item['promotion_name'] ?? null,
                    'promotion_benefit_type' => $item['promotion_benefit_type'] ?? null,
                    'promotion_price_usd' => $item['promotion_price_usd'] ?? null,
                    'promotion_discount_percent' => $item['promotion_discount_percent'] ?? null,
                    'promotion_discount_amount_usd' => $item['promotion_discount_amount_usd'] ?? null,
                    'promotion_adjustment_base_amount' => $item['promotion_adjustment_base_amount'] ?? 0,
                    'promotion_adjustment_local_amount' => $item['promotion_adjustment_local_amount'] ?? 0,
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
            // La venta no se ha replicado localmente (puede llegar despues o
            // ser un evento huerfano). No lanzar: dejarlo como ignorado para
            // no reintentar en cada sync ni ensuciar el log de fallos.
            return 'ignored';
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

    /**
     * Aplica `accounts_payable.created` / `accounts_payable.updated` en la nube.
     * Upsert por (tenant_id, document_number) — identidad natural entre nodos.
     */
    private function applyAccountsPayable(Tenant $tenant, array $payload, array $event): string
    {
        $documentNumber = $this->nullableString($payload['document_number'] ?? null);
        $supplierDocument = $this->nullableString($payload['supplier_document'] ?? null);
        $supplierId = null;

        if ($supplierDocument !== null) {
            $supplierId = DB::table('suppliers')
                ->where('tenant_id', $tenant->id)
                ->where('document_number', $supplierDocument)
                ->value('id');
        }

        // El purchase_order_id del payload es el id LOCAL, no sirve como FK en
        // la nube. Resolvemos el PO de la nube por document_number (identidad
        // natural entre nodos). Si el PO aun no llego, la CxP se aplica sin
        // purchase_order_id y se conserva el documento como referencia.
        $purchaseOrderId = null;
        $poDocument = $this->nullableString($payload['purchase_order_document'] ?? null);

        if ($poDocument === null && $documentNumber !== null) {
            $poDocument = str_starts_with($documentNumber, 'COMPRA-') ? $documentNumber : null;
        }

        if ($poDocument !== null) {
            $purchaseOrderId = DB::table('purchase_orders')
                ->where('tenant_id', $tenant->id)
                ->where('document_number', $poDocument)
                ->value('id');
        }

        $existing = DB::table('accounts_payables')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', $documentNumber)
            ->first();

        $values = [
            'supplier_id' => $supplierId,
            'status' => $payload['status'] ?? 'pending',
            'currency' => $payload['currency'] ?? 'USD',
            'exchange_rate_type_code' => $this->nullableString($payload['exchange_rate_type_code'] ?? null),
            'exchange_rate' => $this->nullableString($payload['exchange_rate'] ?? null),
            'original_base_amount' => (float) ($payload['original_base_amount'] ?? 0),
            'original_local_amount' => (float) ($payload['original_local_amount'] ?? 0),
            'returned_base_amount' => (float) ($payload['returned_base_amount'] ?? 0),
            'returned_local_amount' => (float) ($payload['returned_local_amount'] ?? 0),
            'paid_base_amount' => (float) ($payload['paid_base_amount'] ?? 0),
            'paid_local_amount' => (float) ($payload['paid_local_amount'] ?? 0),
            'adjusted_base_amount' => (float) ($payload['adjusted_base_amount'] ?? 0),
            'adjusted_local_amount' => (float) ($payload['adjusted_local_amount'] ?? 0),
            'balance_base_amount' => (float) ($payload['balance_base_amount'] ?? 0),
            'balance_local_amount' => (float) ($payload['balance_local_amount'] ?? 0),
            'due_date' => $this->nullableString($payload['due_date'] ?? null),
            'opened_at' => $this->nullableString($payload['opened_at'] ?? null),
            'paid_at' => $this->nullableString($payload['paid_at'] ?? null),
            'updated_at' => now(),
        ];

        if ($existing) {
            if ($purchaseOrderId !== null) {
                $values['purchase_order_id'] = (int) $purchaseOrderId;
            }

            DB::table('accounts_payables')
                ->where('id', $existing->id)
                ->update($values);
        } else {
            $values['tenant_id'] = $tenant->id;
            $values['purchase_order_id'] = (int) ($purchaseOrderId ?? 0);
            $values['document_number'] = $documentNumber;
            $values['created_at'] = now();
            $values['updated_at'] = now();

            DB::table('accounts_payables')->insert($values);
        }

        return 'applied';
    }

    /**
     * Aplica `accounts_payable.payment_registered` en la nube.
     * Registra un pago sobre la CxP ya sincronizada por document_number.
     */
    private function applyPayablePayment(Tenant $tenant, array $payload, array $event): string
    {
        $payableDocument = $this->nullableString($payload['payable_document'] ?? null);
        $payment = $payload['payment'] ?? [];

        if ($payableDocument === null || ! is_array($payment)) {
            return 'ignored';
        }

        $payable = DB::table('accounts_payables')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', $payableDocument)
            ->first();

        if (! $payable) {
            return 'ignored';
        }

        $now = now();
        $sourceId = (int) ($payment['id'] ?? 0);
        $ref = $payment['reference'] ?? null;

        $existing = DB::table('accounts_payable_payments')
            ->where('tenant_id', $tenant->id)
            ->when($sourceId > 0, fn ($q) => $q->where('id', $sourceId))
            ->when($ref !== null, fn ($q) => $q->where('reference', $ref))
            ->where('accounts_payable_id', $payable->id)
            ->first();

        if ($existing) {
            return 'applied';
        }

        DB::table('accounts_payable_payments')->insert([
            'tenant_id' => $tenant->id,
            'accounts_payable_id' => $payable->id,
            'payment_currency' => $payment['payment_currency'] ?? $payable->currency ?? 'USD',
            'amount' => (float) ($payment['amount'] ?? 0),
            'exchange_rate_type_code' => $this->nullableString($payment['exchange_rate_type_code'] ?? null),
            'exchange_rate' => $this->nullableString($payment['exchange_rate'] ?? null),
            'amount_base' => (float) ($payment['amount_base'] ?? 0),
            'amount_local' => (float) ($payment['amount_local'] ?? 0),
            'method' => $this->nullableString($payment['method'] ?? null),
            'reference' => $ref,
            'notes' => $this->nullableString($payment['notes'] ?? null),
            'created_by' => null,
            'paid_at' => $this->nullableString($payment['paid_at'] ?? null),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 'applied';
    }

    /**
     * Aplica `accounts_receivable.created` / `accounts_receivable.updated` en la nube.
     * Upsert por (tenant_id, document_number). El customer se resuelve por documento.
     */
    private function applyAccountsReceivable(Tenant $tenant, array $payload, array $event): string
    {
        $documentNumber = $this->nullableString($payload['document_number'] ?? null);
        $saleId = $payload['sale_id'] ?? null;

        // Si el evento trae un sale_id que no existe localmente (por ejemplo
        // una venta pura que nunca se replico, o IDs remotos desalineados),
        // NO intentar insertar: romperia la FK y quedaria en failed
        // reintentandose en cada sync. Marcarlo como ignorado para no
        // bloquear el resto del sync; la CxC se reconciliara por otros medios
        // (document_number) o por un reintento manual si la venta llega.
        if ($saleId !== null) {
            $saleExists = DB::table('sales')
                ->where('tenant_id', $tenant->id)
                ->where('id', (int) $saleId)
                ->exists();
            if (! $saleExists) {
                return 'ignored';
            }
        }

        $customerDocument = $this->nullableString($payload['customer_document'] ?? null);
        $customerId = $customerDocument !== null
            ? DB::table('customers')
                ->where('tenant_id', $tenant->id)
                ->where('document_number', $customerDocument)
                ->value('id')
            : null;

        $existing = DB::table('accounts_receivables')
            ->where('tenant_id', $tenant->id)
            ->where('document_number', $documentNumber)
            ->first();

        $values = [
            'customer_id' => $customerId,
            'status' => $payload['status'] ?? 'pending',
            'currency' => $payload['currency'] ?? 'USD',
            'original_base_amount' => (float) ($payload['original_base_amount'] ?? 0),
            'original_local_amount' => (float) ($payload['original_local_amount'] ?? 0),
            'returned_base_amount' => (float) ($payload['returned_base_amount'] ?? 0),
            'returned_local_amount' => (float) ($payload['returned_local_amount'] ?? 0),
            'collected_base_amount' => (float) ($payload['collected_base_amount'] ?? 0),
            'collected_local_amount' => (float) ($payload['collected_local_amount'] ?? 0),
            'adjusted_base_amount' => (float) ($payload['adjusted_base_amount'] ?? 0),
            'adjusted_local_amount' => (float) ($payload['adjusted_local_amount'] ?? 0),
            'balance_base_amount' => (float) ($payload['balance_base_amount'] ?? 0),
            'balance_local_amount' => (float) ($payload['balance_local_amount'] ?? 0),
            'due_date' => $this->nullableString($payload['due_date'] ?? null),
            'opened_at' => $this->nullableString($payload['opened_at'] ?? null),
            'paid_at' => $this->nullableString($payload['paid_at'] ?? null),
            'updated_at' => now(),
        ];

        if ($existing) {
            if ($saleId !== null) {
                $values['sale_id'] = (int) $saleId;
            }

            DB::table('accounts_receivables')
                ->where('id', $existing->id)
                ->update($values);
        } else {
            $values['tenant_id'] = $tenant->id;
            $values['sale_id'] = (int) ($saleId ?? 0);
            $values['document_number'] = $documentNumber;
            $values['created_at'] = now();
            $values['updated_at'] = now();

            DB::table('accounts_receivables')->insert($values);
        }

        return 'applied';
    }

    /**
     * Aplica `sale.confirmed` (ventas del módulo Sales puro, sin POS) en la nube.
     * Upsert de la venta por (tenant_id, sync_source_node_code, sync_source_id)
     * y replica los sale_items. No aplica stock: las ventas puras del local ya
     * descontaron stock alli; para la nube el stock se reconcilia por los
     * stock_movements que acompanan la operacion.
     */
    private function applySale(Tenant $tenant, array $payload, array $event): string
    {
        $sourceNodeCode = $this->sourceNodeCode($tenant, $event, $payload);
        $sourceSaleId = (int) ($payload['sale_id'] ?? $event['aggregate_id'] ?? 0);

        if ($sourceSaleId <= 0) {
            return 'ignored';
        }

        $now = now();
        $customerId = $this->customerIdByDocument(
            $tenant,
            $payload['customer_document_type'] ?? null,
            $payload['customer_document_number'] ?? null,
        );

        $saleId = $this->upsertAndGetId(
            'sales',
            [
                'tenant_id' => $tenant->id,
                'sync_source_node_code' => $sourceNodeCode,
                'sync_source_id' => $sourceSaleId,
            ],
            [
                'status' => $payload['status'] ?? 'confirmed',
                'customer_id' => $customerId,
                'total_base_amount' => $payload['total_base_amount'] ?? 0,
                'total_local_amount' => $payload['total_local_amount'] ?? 0,
                'created_by' => null,
                'confirmed_at' => $this->nullableDate($payload['confirmed_at'] ?? null) ?? $now,
                'cancelled_at' => $this->nullableDate($payload['cancelled_at'] ?? null),
                'updated_at' => $now,
            ]
        );

        $this->syncPlainSaleItems($tenant, $saleId, $sourceNodeCode, $payload['items'] ?? []);

        return 'applied';
    }

    /**
     * Replica los sale_items de una venta pura (sin POS). La identidad de sync
     * por item se deriva de (sale_id local + indice) porque el payload del
     * evento no incluye el item_id local.
     */
    private function syncPlainSaleItems(Tenant $tenant, int $saleId, string $sourceNodeCode, array $items): void
    {
        $now = now();

        foreach ($items as $index => $item) {
            $sku = $this->nullableString($item['sku'] ?? null);
            $warehouseCode = $this->nullableString($item['warehouse_code'] ?? null);

            if ($sku === null || $warehouseCode === null) {
                continue;
            }

            $product = $this->productBySku($tenant, $sku);
            $warehouse = $this->warehouseByCode($tenant, $warehouseCode);
            $priceListId = $this->nullablePriceListIdByCode($tenant, $item['price_list_code'] ?? null);
            $sourceItemId = $saleId * 1000 + $index + 1;

            $this->upsertByKeys(
                'sale_items',
                [
                    'tenant_id' => $tenant->id,
                    'sync_source_node_code' => $sourceNodeCode,
                    'sync_source_id' => $sourceItemId,
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
                    'exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $item['exchange_rate_type_code'] ?? null, null),
                    'exchange_rate_type_code' => $item['exchange_rate_type_code'] ?? null,
                    'exchange_rate' => $item['exchange_rate'] ?? null,
                    'stock_movement_id' => null,
                    'product_unit_ids' => isset($item['product_unit_ids']) ? json_encode($item['product_unit_ids']) : null,
                    'discount_type' => $item['discount_type'] ?? null,
                    'discount_value' => $item['discount_value'] ?? 0,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'promotion_code' => $item['promotion_code'] ?? null,
                    'promotion_name' => $item['promotion_name'] ?? null,
                    'promotion_benefit_type' => $item['promotion_benefit_type'] ?? null,
                    'updated_at' => $now,
                ]
            );
        }
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
            'updated_at' => $now,
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
            'updated_at' => now(),
        ]));
    }

    private function upsertAndGetId(string $table, array $keys, array $values): int
    {
        $this->upsertByKeys($table, $keys, $values);

        return (int) DB::table($table)->where($keys)->value('id');
    }

    private function rememberEntityMapping(Tenant $tenant, string $entityType, int $remoteId, int $localId, ?string $remoteKey = null): void
    {
        $remoteTenantId = DB::table('sync_tenant_mappings')
            ->where('local_tenant_id', $tenant->id)
            ->value('remote_tenant_id');

        if (! $remoteTenantId || $remoteId <= 0 || $localId <= 0) {
            return;
        }

        DB::table('sync_entity_mappings')->updateOrInsert(
            [
                'entity_type' => $entityType,
                'remote_tenant_id' => (int) $remoteTenantId,
                'remote_id' => $remoteId,
            ],
            [
                'local_tenant_id' => $tenant->id,
                'local_id' => $localId,
                'remote_key' => $remoteKey,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function localTenantIdForRemote(int $remoteTenantId): int
    {
        if ($remoteTenantId <= 0) {
            return 0;
        }

        $mapped = DB::table('sync_tenant_mappings')
            ->where('remote_tenant_id', $remoteTenantId)
            ->value('local_tenant_id');

        if ($mapped) {
            return (int) $mapped;
        }

        $current = app(TenantManager::class)->current();
        if ($current) {
            $currentRemoteId = DB::table('sync_tenant_mappings')
                ->where('local_tenant_id', $current->id)
                ->value('remote_tenant_id');

            if ((int) $currentRemoteId === $remoteTenantId) {
                return $current->id;
            }
        }

        // Legacy single-tenant installations used cloud IDs directly.
        if (Tenant::withoutGlobalScopes()->whereKey($remoteTenantId)->exists()) {
            return $remoteTenantId;
        }

        throw new RuntimeException("No se encontro el mapeo local del tenant remoto {$remoteTenantId}.");
    }

    private function localEntityId(string $entityType, int $remoteTenantId, int $remoteId): ?int
    {
        if ($remoteId <= 0) {
            return null;
        }

        $mapped = DB::table('sync_entity_mappings')
            ->where('entity_type', $entityType)
            ->where('remote_tenant_id', $remoteTenantId)
            ->where('remote_id', $remoteId)
            ->value('local_id');

        if ($mapped) {
            return (int) $mapped;
        }

        $localTenantId = $this->localTenantIdForRemote($remoteTenantId);
        $table = match ($entityType) {
            'branch' => 'branches',
            'product' => 'products',
            'warehouse' => 'warehouses',
            default => throw new RuntimeException("Tipo de entidad sincronizada no soportado: {$entityType}."),
        };

        if (DB::table($table)->where('tenant_id', $localTenantId)->where('id', $remoteId)->exists()) {
            return $remoteId;
        }

        throw new RuntimeException("No se encontro el mapeo local del {$entityType} remoto {$remoteId}.");
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

    /**
     * Aplica `purchase_return.created` (devolución de compra al proveedor) en
     * el nodo destino. La devolución es una SALIDA de stock: decrementa
     * stock_balances y marca los seriales como removed. Luego actualiza la CxP
     * de la orden (AccountsPayableService::applyPurchaseReturn).
     *
     * Idempotente: upsert por (tenant_id, sync_source_node_code, sync_source_id).
     */
    private function applyPurchaseReturn(Tenant $tenant, array $payload, array $event): string
    {
        $sourceNodeCode = $this->sourceNodeCode($tenant, $event, $payload);
        $sourceReturnId = (int) ($payload['return_id'] ?? $event['aggregate_id'] ?? 0);

        if ($sourceReturnId <= 0) {
            return 'ignored';
        }

        $poDocument = $this->nullableString($payload['purchase_order_document'] ?? null);
        $purchaseOrderId = $poDocument !== null
            ? DB::table('purchase_orders')
                ->where('tenant_id', $tenant->id)
                ->where('document_number', $poDocument)
                ->value('id')
            : null;

        if (! $purchaseOrderId) {
            // El PO origen aún no llegó; reintentar más tarde.
            throw new RuntimeException('No se encontro la orden de compra origen para aplicar la devolucion.');
        }

        $now = now();

        $previousStatus = DB::table('purchase_returns')
            ->where('tenant_id', $tenant->id)
            ->where('sync_source_node_code', $sourceNodeCode)
            ->where('sync_source_id', $sourceReturnId)
            ->value('status');

        $returnId = $this->upsertAndGetId(
            'purchase_returns',
            [
                'tenant_id' => $tenant->id,
                'sync_source_node_code' => $sourceNodeCode,
                'sync_source_id' => $sourceReturnId,
            ],
            [
                'purchase_order_id' => $purchaseOrderId,
                'status' => $payload['status'] ?? 'processed',
                'reason' => $this->nullableString($payload['reason'] ?? null),
                'created_by' => null,
                'processed_at' => $this->nullableDate($payload['processed_at'] ?? null) ?? $now,
                'updated_at' => $now,
            ]
        );

        $previousProcessed = $previousStatus === 'processed';

        foreach ($payload['items'] ?? [] as $index => $item) {
            $sku = $this->nullableString($item['sku'] ?? null);
            $warehouseCode = $this->nullableString($item['warehouse_code'] ?? null);

            if ($sku === null || $warehouseCode === null) {
                continue;
            }

            $product = $this->productBySku($tenant, $sku);
            $warehouse = $this->warehouseByCode($tenant, $warehouseCode);
            $quantity = (float) ($item['quantity'] ?? 0);

            if ($quantity <= 0.0) {
                throw new RuntimeException('La devolucion sincronizada contiene una cantidad invalida.');
            }

            $sourceItemId = $sourceReturnId * 1000 + $index + 1;

            // Resolver el purchase_item del PO en la nube (por producto).
            // Los items de compra no viajan por sync; se usan como referencia
            // para cumplir la FK purchase_return_items.purchase_item_id.
            $purchaseItemId = DB::table('purchase_items')
                ->where('tenant_id', $tenant->id)
                ->where('purchase_order_id', $purchaseOrderId)
                ->where('product_id', $product->id)
                ->value('id');

            $returnItemId = $this->upsertAndGetId(
                'purchase_return_items',
                [
                    'tenant_id' => $tenant->id,
                    'sync_source_node_code' => $sourceNodeCode,
                    'sync_source_id' => $sourceItemId,
                ],
                [
                    'purchase_return_id' => $returnId,
                    'purchase_item_id' => (int) ($purchaseItemId ?? 0),
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'reason' => $this->nullableString($item['reason'] ?? null),
                    'updated_at' => $now,
                ]
            );

            $alreadyHasMovement = DB::table('purchase_return_items')
                ->where('id', $returnItemId)
                ->value('stock_movement_id');

            if ($alreadyHasMovement || $previousProcessed) {
                continue;
            }

            $movement = app(InventoryMovementService::class)->purchaseReturn(
                Warehouse::query()->findOrFail($warehouse->id),
                Product::query()->findOrFail($product->id),
                $quantity,
                null,
                $item['reason'] ?? $payload['reason'] ?? "Devolucion sincronizada #{$sourceReturnId}",
                PurchaseReturn::class,
                $returnId,
            );

            DB::table('purchase_return_items')->where('id', $returnItemId)->update([
                'stock_movement_id' => $movement->id,
                'updated_at' => $now,
            ]);

            $serialUnits = $item['product_serial_units'] ?? [];
            if (is_array($serialUnits) && $serialUnits !== []) {
                $this->markCloudSerialUnitsRemoved($tenant, $product->id, $warehouse->id, $serialUnits, $movement->id);
            }
        }

        if ($poDocument !== null && ! $previousProcessed) {
            $po = DB::table('purchase_orders')
                ->where('tenant_id', $tenant->id)
                ->where('document_number', $poDocument)
                ->first();

            if ($po) {
                $return = PurchaseReturn::query()
                    ->withoutGlobalScopes()
                    ->with('items')
                    ->find($returnId);

                if ($return) {
                    app(AccountsPayableService::class)
                        ->applyPurchaseReturn($return);
                }
            }
        }

        return 'applied';
    }

    /**
     * Marca los seriales devueltos como removed en la nube (salida al proveedor).
     */
    private function markCloudSerialUnitsRemoved(Tenant $tenant, int $productId, int $warehouseId, array $serialUnits, int $movementId): void
    {
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
                ->where('status', 'available')
                ->first();

            if (! $unit) {
                continue;
            }

            DB::table('product_units')
                ->where('id', $unit->id)
                ->update([
                    'status' => 'removed',
                    'released_stock_movement_id' => $movementId,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Aplica `user.roles.synced` (cambios de usuario/membresía/roles) en el
     * nodo destino. Es el mecanismo que hace viajar permisos y accesos entre
     * local y nube (antes no viajaban).
     *
     * - Upsert del usuario por email (incluye password hash para login local).
     * - Upsert de la membresía tenant_user (status active/inactive).
     * - Sincroniza los roles del usuario en el tenant (por nombre; crea el rol
     *   si no existe y le asigna los permisos base del catálogo).
     */
    private function applyUserRoles(Tenant $tenant, array $payload): string
    {
        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));

        if ($email === '') {
            return 'ignored';
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = (string) ($payload['name'] ?? $user->name ?? $email);
        $passwordHash = $this->nullableString($payload['password_hash'] ?? null);

        if ($passwordHash !== null && $passwordHash !== '') {
            $user->password = $passwordHash;
        }

        if (array_key_exists('is_platform_admin', $payload)) {
            $user->is_platform_admin = (bool) $payload['is_platform_admin'];
        }

        $user->save();

        $isActive = array_key_exists('is_active', $payload)
            ? (bool) $payload['is_active']
            : true;

        $pivot = DB::table('tenant_user')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();

        if ($pivot) {
            DB::table('tenant_user')
                ->where('id', $pivot->id)
                ->update([
                    'status' => $isActive ? 'active' : 'inactive',
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('tenant_user')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'status' => $isActive ? 'active' : 'inactive',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Sincronizar roles por nombre en el tenant destino.
        $roleNames = array_values(array_filter(array_map(
            fn (mixed $role): string => trim((string) $role),
            $payload['roles'] ?? []
        )));

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }

        $roleIds = [];
        foreach ($roleNames as $roleName) {
            $role = Role::query()
                ->where('tenant_id', $tenant->id)
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->first();

            if (! $role) {
                $role = Role::create([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->id,
                ]);
            }

            $roleIds[] = $role->id;
        }

        DB::table('model_has_roles')
            ->where('tenant_id', $tenant->id)
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->whereNotIn('role_id', $roleIds)
            ->delete();

        foreach ($roleIds as $roleId) {
            DB::table('model_has_roles')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'role_id' => $roleId,
                    'model_id' => $user->id,
                    'model_type' => User::class,
                ]
            );
        }

        return 'applied';
    }
}
