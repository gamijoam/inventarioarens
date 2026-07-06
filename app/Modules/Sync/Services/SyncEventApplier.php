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
            'product.updated', 'product.created' => $this->applyProduct($tenant, $payload),
            'price_list.updated', 'price_list.created' => $this->applyPriceList($tenant, $payload),
            'product_price.updated', 'product_price.created', 'price.updated' => $this->applyProductPrice($tenant, $payload),
            'exchange_rate_type.updated', 'exchange_rate_type.created' => $this->applyExchangeRateType($tenant, $payload),
            'exchange_rate.updated', 'exchange_rate.created' => $this->applyExchangeRate($tenant, $payload),
            'payment_method.updated', 'payment_method.created' => $this->applyPaymentMethod($tenant, $payload),
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
            'warranty_policy_id' => $payload['warranty_policy_id'] ?? null,
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

    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        if ($value === '') {
            throw new RuntimeException("El evento de sincronizacion no incluye {$key}.");
        }

        return $value;
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
