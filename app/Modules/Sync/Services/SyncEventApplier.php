<?php

namespace App\Modules\Sync\Services;

use App\Modules\Products\Models\ProductAudit;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        'pos.order.pending',
        'pos.order.payment_added',
        'pos.order.paid',
        'pos.order.cancelled',
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
        $payload = $this->decodePayload($event['payload'] ?? []);

        $result = match ($event['event_type']) {
            'branch.updated', 'branch.created' => $this->applyBranch($tenant, $payload),
            'warehouse.updated', 'warehouse.created' => $this->applyWarehouse($tenant, $payload),
            'product.updated', 'product.created' => $this->applyProduct($tenant, $payload),
            'customer.updated', 'customer.created' => $this->applyCustomer($tenant, $payload),
            'stock_movement.updated', 'stock_movement.created' => $this->applyStockMovement($tenant, $payload),
            'product_unit.updated', 'product_unit.created' => $this->applyProductUnit($tenant, $payload),
            'price_list.updated', 'price_list.created' => $this->applyPriceList($tenant, $payload),
            'product_price.updated', 'product_price.created', 'price.updated' => $this->applyProductPrice($tenant, $payload),
            'exchange_rate_type.updated', 'exchange_rate_type.created' => $this->applyExchangeRateType($tenant, $payload),
            'exchange_rate.updated', 'exchange_rate.created' => $this->applyExchangeRate($tenant, $payload),
            'payment_method.updated', 'payment_method.created' => $this->applyPaymentMethod($tenant, $payload),
            'cash_register.updated', 'cash_register.created' => $this->applyCashRegister($tenant, $payload),
            'inventory_transfer.updated', 'inventory_transfer.created' => $this->applyInventoryTransfer($tenant, $payload),
            'pos.order.pending', 'pos.order.payment_added', 'pos.order.paid', 'pos.order.cancelled' => $this->applyPosOrder($tenant, $payload, $event),
            default => 'ignored',
        };

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
            'tracking_type' => $payload['tracking_type'] ?? 'quantity',
            'base_price' => $payload['base_price'] ?? null,
            'sale_currency' => strtoupper($payload['sale_currency'] ?? 'USD'),
            'sale_exchange_rate_type_id' => $this->exchangeRateTypeId($tenant, $payload['sale_exchange_rate_type_code'] ?? null, $payload['sale_exchange_rate_type_id'] ?? null),
            'warranty_policy_id' => $this->warrantyPolicyId($tenant, $payload),
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
        $this->recordProductAudit($productId, $before, $after);

        return 'applied';
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
            $sourceItemId = (int) ($itemPayload['id'] ?? 0);
            $keys = $sourceItemId > 0
                ? ['tenant_id' => $tenant->id, 'id' => $sourceItemId]
                : ['tenant_id' => $tenant->id, 'inventory_transfer_id' => $transferId, 'product_id' => $product->id];

            $this->upsertAndGetId(
                'inventory_transfer_items',
                $keys,
                [
                    'inventory_transfer_id' => $transferId,
                    'product_id' => $product->id,
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
        $salePayload = $payload['sale'] ?? $payload;
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

        $this->syncPosSaleItems($tenant, $saleId, $sourceNodeCode, $payload['items'] ?? []);
        $this->syncPosPayments($tenant, $orderId, $sourceNodeCode, $payload['payments'] ?? []);

        return 'applied';
    }

    private function syncPosSaleItems(Tenant $tenant, int $saleId, string $sourceNodeCode, array $items): void
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
        }

        DB::table('sale_items')
            ->where('tenant_id', $tenant->id)
            ->where('sale_id', $saleId)
            ->where('sync_source_node_code', $sourceNodeCode)
            ->whereNotIn('sync_source_id', $sourceIds)
            ->delete();
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
