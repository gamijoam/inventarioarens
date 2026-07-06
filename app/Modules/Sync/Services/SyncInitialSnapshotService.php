<?php

namespace App\Modules\Sync\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncInitialSnapshotService
{
    public function queueForNode(Tenant $tenant, int $targetNodeId, string $installationCode): array
    {
        $this->clearPreviousSnapshot($tenant, $targetNodeId, $installationCode);

        $summary = [
            'branch.created' => $this->queueBranches($tenant, $targetNodeId, $installationCode),
            'warehouse.created' => $this->queueWarehouses($tenant, $targetNodeId, $installationCode),
            'exchange_rate_type.created' => $this->queueExchangeRateTypes($tenant, $targetNodeId, $installationCode),
            'exchange_rate.created' => $this->queueExchangeRates($tenant, $targetNodeId, $installationCode),
            'payment_method.created' => $this->queuePaymentMethods($tenant, $targetNodeId, $installationCode),
            'price_list.created' => $this->queuePriceLists($tenant, $targetNodeId, $installationCode),
            'product.created' => $this->queueProducts($tenant, $targetNodeId, $installationCode),
            'product_price.created' => $this->queueProductPrices($tenant, $targetNodeId, $installationCode),
            'stock_movement.created' => $this->queueStockMovements($tenant, $targetNodeId, $installationCode),
            'product_unit.created' => $this->queueProductUnits($tenant, $targetNodeId, $installationCode),
            'cash_register.created' => $this->queueCashRegisters($tenant, $targetNodeId, $installationCode),
        ];

        return [
            'queued' => array_sum($summary),
            'events' => $summary,
        ];
    }

    private function clearPreviousSnapshot(Tenant $tenant, int $targetNodeId, string $installationCode): void
    {
        DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('target_node_id', $targetNodeId)
            ->where('idempotency_key', 'like', $this->snapshotPrefix($installationCode).'%')
            ->delete();
    }

    private function queueBranches(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('branches')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($branches) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($branches as $branch) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'branch.created', 'branch', (int) $branch->id, [
                        'code' => $branch->code,
                        'name' => $branch->name,
                        'status' => $branch->status,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueWarehouses(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('warehouses')
            ->join('branches', function ($join): void {
                $join->on('branches.id', '=', 'warehouses.branch_id')
                    ->on('branches.tenant_id', '=', 'warehouses.tenant_id');
            })
            ->where('warehouses.tenant_id', $tenant->id)
            ->orderBy('warehouses.id')
            ->select('warehouses.*', 'branches.code as branch_code')
            ->chunkById(200, function ($warehouses) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($warehouses as $warehouse) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'warehouse.created', 'warehouse', (int) $warehouse->id, [
                        'code' => $warehouse->code,
                        'name' => $warehouse->name,
                        'status' => $warehouse->status,
                        'branch_code' => $warehouse->branch_code,
                    ]);
                    $count++;
                }
            }, 'warehouses.id', 'id');

        return $count;
    }

    private function queueExchangeRateTypes(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('exchange_rate_types')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($types) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($types as $type) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'exchange_rate_type.created', 'exchange_rate_type', (int) $type->id, [
                        'code' => $type->code,
                        'name' => $type->name,
                        'is_default' => (bool) $type->is_default,
                        'is_active' => (bool) $type->is_active,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueExchangeRates(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('exchange_rates')
            ->join('exchange_rate_types', function ($join): void {
                $join->on('exchange_rate_types.id', '=', 'exchange_rates.exchange_rate_type_id')
                    ->on('exchange_rate_types.tenant_id', '=', 'exchange_rates.tenant_id');
            })
            ->where('exchange_rates.tenant_id', $tenant->id)
            ->orderBy('exchange_rates.id')
            ->select('exchange_rates.*', 'exchange_rate_types.code as exchange_rate_type_code')
            ->chunkById(200, function ($rates) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($rates as $rate) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'exchange_rate.created', 'exchange_rate', (int) $rate->id, [
                        'exchange_rate_type_code' => $rate->exchange_rate_type_code,
                        'base_currency' => $rate->base_currency,
                        'quote_currency' => $rate->quote_currency,
                        'rate' => (string) $rate->rate,
                        'effective_at' => Carbon::parse($rate->effective_at)->toISOString(),
                        'source' => $rate->source,
                        'is_active' => (bool) $rate->is_active,
                    ]);
                    $count++;
                }
            }, 'exchange_rates.id', 'id');

        return $count;
    }

    private function queuePaymentMethods(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('payment_methods')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($methods) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($methods as $method) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'payment_method.created', 'payment_method', (int) $method->id, [
                        'code' => $method->code,
                        'name' => $method->name,
                        'method' => $method->method,
                        'currency_mode' => $method->currency_mode,
                        'requires_reference' => (bool) $method->requires_reference,
                        'is_active' => (bool) $method->is_active,
                        'sort_order' => (int) $method->sort_order,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queuePriceLists(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('price_lists')
            ->where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->chunkById(200, function ($lists) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($lists as $list) {
                    $methodCodes = DB::table('price_list_payment_method')
                        ->join('payment_methods', function ($join): void {
                            $join->on('payment_methods.id', '=', 'price_list_payment_method.payment_method_id')
                                ->on('payment_methods.tenant_id', '=', 'price_list_payment_method.tenant_id');
                        })
                        ->where('price_list_payment_method.tenant_id', $tenant->id)
                        ->where('price_list_payment_method.price_list_id', $list->id)
                        ->pluck('payment_methods.code')
                        ->all();

                    $this->record($tenant, $targetNodeId, $installationCode, 'price_list.created', 'price_list', (int) $list->id, [
                        'code' => $list->code,
                        'name' => $list->name,
                        'description' => $list->description,
                        'is_default' => (bool) $list->is_default,
                        'is_active' => (bool) $list->is_active,
                        'sort_order' => (int) $list->sort_order,
                        'payment_method_codes' => $methodCodes,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueProducts(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('products')
            ->leftJoin('exchange_rate_types', function ($join): void {
                $join->on('exchange_rate_types.id', '=', 'products.sale_exchange_rate_type_id')
                    ->on('exchange_rate_types.tenant_id', '=', 'products.tenant_id');
            })
            ->where('products.tenant_id', $tenant->id)
            ->orderBy('products.id')
            ->select('products.*', 'exchange_rate_types.code as sale_exchange_rate_type_code')
            ->chunkById(200, function ($products) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($products as $product) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'product.created', 'product', (int) $product->id, [
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'tracking_type' => $product->tracking_type,
                        'base_price' => $product->base_price === null ? null : (string) $product->base_price,
                        'sale_currency' => $product->sale_currency,
                        'sale_exchange_rate_type_code' => $product->sale_exchange_rate_type_code,
                        'warranty_policy_id' => null,
                        'is_active' => (bool) $product->is_active,
                    ]);
                    $count++;
                }
            }, 'products.id', 'id');

        return $count;
    }

    private function queueProductPrices(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('product_prices')
            ->join('products', function ($join): void {
                $join->on('products.id', '=', 'product_prices.product_id')
                    ->on('products.tenant_id', '=', 'product_prices.tenant_id');
            })
            ->join('price_lists', function ($join): void {
                $join->on('price_lists.id', '=', 'product_prices.price_list_id')
                    ->on('price_lists.tenant_id', '=', 'product_prices.tenant_id');
            })
            ->leftJoin('exchange_rate_types', function ($join): void {
                $join->on('exchange_rate_types.id', '=', 'product_prices.exchange_rate_type_id')
                    ->on('exchange_rate_types.tenant_id', '=', 'product_prices.tenant_id');
            })
            ->where('product_prices.tenant_id', $tenant->id)
            ->orderBy('product_prices.id')
            ->select('product_prices.*', 'products.sku', 'price_lists.code as price_list_code', 'exchange_rate_types.code as exchange_rate_type_code')
            ->chunkById(200, function ($prices) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($prices as $price) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'product_price.created', 'product_price', (int) $price->id, [
                        'sku' => $price->sku,
                        'price_list_code' => $price->price_list_code,
                        'price' => (string) $price->price,
                        'currency' => $price->currency,
                        'exchange_rate_type_code' => $price->exchange_rate_type_code,
                        'is_active' => (bool) $price->is_active,
                    ]);
                    $count++;
                }
            }, 'product_prices.id', 'id');

        return $count;
    }

    private function queueStockMovements(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('stock_movements')
            ->join('products', function ($join): void {
                $join->on('products.id', '=', 'stock_movements.product_id')
                    ->on('products.tenant_id', '=', 'stock_movements.tenant_id');
            })
            ->join('warehouses', function ($join): void {
                $join->on('warehouses.id', '=', 'stock_movements.warehouse_id')
                    ->on('warehouses.tenant_id', '=', 'stock_movements.tenant_id');
            })
            ->where('stock_movements.tenant_id', $tenant->id)
            ->orderBy('stock_movements.id')
            ->select('stock_movements.*', 'products.sku', 'warehouses.code as warehouse_code')
            ->chunkById(200, function ($movements) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($movements as $movement) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'stock_movement.created', 'stock_movement', (int) $movement->id, [
                        'source_id' => (int) $movement->id,
                        'sku' => $movement->sku,
                        'warehouse_code' => $movement->warehouse_code,
                        'type' => $movement->type,
                        'quantity' => (string) $movement->quantity,
                        'unit_cost' => $movement->unit_cost === null ? null : (string) $movement->unit_cost,
                        'reason' => $movement->reason,
                        'reference_type' => $movement->reference_type,
                        'reference_id' => $movement->reference_id,
                        'created_at' => Carbon::parse($movement->created_at)->toISOString(),
                    ]);
                    $count++;
                }
            }, 'stock_movements.id', 'id');

        return $count;
    }

    private function queueProductUnits(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('product_units')
            ->join('products', function ($join): void {
                $join->on('products.id', '=', 'product_units.product_id')
                    ->on('products.tenant_id', '=', 'product_units.tenant_id');
            })
            ->leftJoin('warehouses', function ($join): void {
                $join->on('warehouses.id', '=', 'product_units.warehouse_id')
                    ->on('warehouses.tenant_id', '=', 'product_units.tenant_id');
            })
            ->where('product_units.tenant_id', $tenant->id)
            ->orderBy('product_units.id')
            ->select('product_units.*', 'products.sku', 'warehouses.code as warehouse_code')
            ->chunkById(200, function ($units) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($units as $unit) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'product_unit.created', 'product_unit', (int) $unit->id, [
                        'sku' => $unit->sku,
                        'warehouse_code' => $unit->warehouse_code,
                        'serial_type' => $unit->serial_type,
                        'serial_number' => $unit->serial_number,
                        'status' => $unit->status,
                    ]);
                    $count++;
                }
            }, 'product_units.id', 'id');

        return $count;
    }

    private function queueCashRegisters(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('cash_registers')
            ->join('branches', function ($join): void {
                $join->on('branches.id', '=', 'cash_registers.branch_id')
                    ->on('branches.tenant_id', '=', 'cash_registers.tenant_id');
            })
            ->where('cash_registers.tenant_id', $tenant->id)
            ->orderBy('cash_registers.id')
            ->select('cash_registers.*', 'branches.code as branch_code')
            ->chunkById(200, function ($registers) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($registers as $register) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'cash_register.created', 'cash_register', (int) $register->id, [
                        'code' => $register->code,
                        'name' => $register->name,
                        'status' => $register->status,
                        'notes' => $register->notes,
                        'branch_code' => $register->branch_code,
                    ]);
                    $count++;
                }
            }, 'cash_registers.id', 'id');

        return $count;
    }

    private function record(
        Tenant $tenant,
        int $targetNodeId,
        string $installationCode,
        string $eventType,
        string $aggregateType,
        int $aggregateId,
        array $payload,
    ): void {
        app(TenantManager::class)->set($tenant);

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'origin_node_id' => null,
            'target_node_id' => $targetNodeId,
            'target_scope' => 'node',
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => json_encode($payload),
            'occurred_at' => now(),
            'available_at' => now(),
            'status' => 'pending',
            'idempotency_key' => $this->snapshotPrefix($installationCode).$eventType.':'.$aggregateType.':'.$aggregateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function snapshotPrefix(string $installationCode): string
    {
        return 'initial-snapshot:'.$installationCode.':';
    }
}
