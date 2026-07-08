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
    public function applyPending(Tenant $tenant, int $limit = 50): array
    {
        $events = DB::table('sync_inbox')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'received')
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
            ->where('status', 'received')
            ->whereIn('event_uuid', $eventUuids)
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
