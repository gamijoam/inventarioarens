<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryReconciliationService
{
    private const AVAILABLE_DELTAS = [
        'purchase' => 1,
        'purchase_return' => -1,
        'sale' => -1,
        'sale_return' => 1,
        'sale_reversal' => 1,
        'adjustment_in' => 1,
        'adjustment_out' => -1,
        'transfer_in' => 1,
        'transfer_out' => -1,
        'transfer_request_in' => 1,
        'transfer_request_out' => -1,
        'return_in' => 1,
        'return_out' => -1,
        'damaged' => -1,
        'reserved' => -1,
        'released' => 1,
    ];

    public function reconcileTenant(
        int $tenantId,
        bool $fix = false,
        bool $dryRun = false,
        bool $fixSerials = false,
    ): array {
        $expected = $this->expectedBalances($tenantId);
        $actual = $this->actualBalances($tenantId);
        $keys = array_values(array_unique([...array_keys($expected), ...array_keys($actual)]));
        $drifts = [];
        $fixed = 0;
        $fixedKeys = [];

        foreach ($keys as $key) {
            $expectedBalance = $expected[$key] ?? $this->zeroBalance();
            $actualBalance = $actual[$key]['totals'] ?? $this->zeroBalance();
            if ($this->matches($expectedBalance, $actualBalance)) {
                continue;
            }

            $drifts[] = [
                'key' => $key,
                'expected' => $expectedBalance,
                'actual' => $actualBalance,
            ];

            if ($fix && ! $dryRun) {
                $this->fixBalance($tenantId, $key, $expectedBalance, $actual[$key]['rows'] ?? []);
                $fixed++;
                $fixedKeys[$key] = true;
            }
        }

        $serialDrifts = $this->serialDrifts($tenantId, $actual);
        foreach ($serialDrifts as $drift) {
            if ($fixSerials && ! $dryRun) {
                $this->fixBalance($tenantId, $drift['key'], $drift['expected'], $drift['rows']);
                if (! isset($fixedKeys[$drift['key']])) {
                    $fixed++;
                }
            }
        }

        return [
            'drifts' => $drifts,
            'serial_drifts' => $serialDrifts,
            'fixed' => $fixed,
        ];
    }

    /**
     * @return array<string, array{available: float, reserved: float, damaged: float}>
     */
    private function expectedBalances(int $tenantId): array
    {
        $expected = [];
        $movements = StockMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->select(['warehouse_id', 'product_id', 'product_variant_id', 'type', 'quantity'])
            ->get();

        foreach ($movements as $movement) {
            $key = $this->key($movement->warehouse_id, $movement->product_id, $movement->product_variant_id);
            $expected[$key] ??= $this->zeroBalance();
            $quantity = (float) $movement->quantity;
            $expected[$key]['available'] += $quantity * (self::AVAILABLE_DELTAS[$movement->type] ?? 0);

            if ($movement->type === 'reserved') {
                $expected[$key]['reserved'] += $quantity;
            } elseif ($movement->type === 'released') {
                $expected[$key]['reserved'] -= $quantity;
            } elseif ($movement->type === 'damaged') {
                $expected[$key]['damaged'] += $quantity;
            }
        }

        foreach ($expected as &$balance) {
            $balance['available'] = max(0.0, $balance['available']);
            $balance['reserved'] = max(0.0, $balance['reserved']);
            $balance['damaged'] = max(0.0, $balance['damaged']);
        }
        unset($balance);

        return $expected;
    }

    /**
     * @return array<string, array{totals: array{available: float, reserved: float, damaged: float}, rows: array<int, object> }>
     */
    private function actualBalances(int $tenantId): array
    {
        $actual = [];
        $balances = DB::table('stock_balances')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get();

        foreach ($balances as $balance) {
            $key = $this->key($balance->warehouse_id, $balance->product_id, $balance->product_variant_id);
            $actual[$key] ??= ['totals' => $this->zeroBalance(), 'rows' => []];
            $actual[$key]['rows'][] = $balance;
            $actual[$key]['totals']['available'] += (float) $balance->quantity_available;
            $actual[$key]['totals']['reserved'] += (float) $balance->quantity_reserved;
            $actual[$key]['totals']['damaged'] += (float) $balance->quantity_damaged;
        }

        return $actual;
    }

    /**
     * @param  array<string, array{totals: array{available: float, reserved: float, damaged: float}, rows: array<int, object>}>  $actual
     * @return array<int, array{key: string, expected: array{available: float, reserved: float, damaged: float}, actual: array{available: float, reserved: float, damaged: float}, rows: array<int, object> }>
     */
    private function serialDrifts(int $tenantId, array $actual): array
    {
        $serializedProductIds = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('tracking_type', Product::TRACKING_SERIALIZED)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($serializedProductIds === []) {
            return [];
        }

        $expected = [];
        $units = DB::table('product_units')
            ->where('tenant_id', $tenantId)
            ->whereIn('product_id', $serializedProductIds)
            ->whereNotNull('warehouse_id')
            ->whereIn('status', ['available', 'reserved', 'damaged'])
            ->select(['warehouse_id', 'product_id', 'product_variant_id', 'status'])
            ->get();

        foreach ($units as $unit) {
            $key = $this->key($unit->warehouse_id, $unit->product_id, $unit->product_variant_id);
            $expected[$key] ??= $this->zeroBalance();
            $expected[$key][$this->unitBucket((string) $unit->status)]++;
        }

        $drifts = [];
        foreach ($actual as $key => $balance) {
            [, $productId] = explode('|', $key, 3);
            if (! in_array((int) $productId, $serializedProductIds, true)) {
                continue;
            }

            $expectedBalance = $expected[$key] ?? $this->zeroBalance();
            if ($this->matches($expectedBalance, $balance['totals'])) {
                continue;
            }

            $drifts[] = [
                'key' => $key,
                'expected' => $expectedBalance,
                'actual' => $balance['totals'],
                'rows' => $balance['rows'],
            ];
        }

        foreach ($expected as $key => $expectedBalance) {
            if (array_key_exists($key, $actual)) {
                continue;
            }

            $drifts[] = [
                'key' => $key,
                'expected' => $expectedBalance,
                'actual' => $this->zeroBalance(),
                'rows' => [],
            ];
        }

        return $drifts;
    }

    private function unitBucket(string $status): string
    {
        return match ($status) {
            'reserved' => 'reserved',
            'damaged' => 'damaged',
            default => 'available',
        };
    }

    /**
     * @param  array{available: float, reserved: float, damaged: float}  $expected
     * @param  array<int, object>  $rows
     */
    private function fixBalance(int $tenantId, string $key, array $expected, array $rows): void
    {
        [$warehouseId, $productId, $variantId] = array_map(
            static fn (string $value): ?int => $value === 'null' ? null : (int) $value,
            explode('|', $key),
        );
        $row = $rows[0] ?? null;

        if ($row) {
            DB::table('stock_balances')->where('id', $row->id)->update([
                'quantity_available' => $expected['available'],
                'quantity_reserved' => $expected['reserved'],
                'quantity_damaged' => $expected['damaged'],
            ]);

            foreach (array_slice($rows, 1) as $duplicate) {
                DB::table('stock_balances')->where('id', $duplicate->id)->update([
                    'quantity_available' => 0,
                    'quantity_reserved' => 0,
                    'quantity_damaged' => 0,
                ]);
            }

            return;
        }

        DB::table('stock_balances')->insert([
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'location_id' => null,
            'quantity_available' => $expected['available'],
            'quantity_reserved' => $expected['reserved'],
            'quantity_damaged' => $expected['damaged'],
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array{available: float, reserved: float, damaged: float}  $left
     * @param  array{available: float, reserved: float, damaged: float}  $right
     */
    private function matches(array $left, array $right): bool
    {
        foreach (['available', 'reserved', 'damaged'] as $bucket) {
            if (abs($left[$bucket] - $right[$bucket]) > 0.0001) {
                return false;
            }
        }

        return true;
    }

    /** @return array{available: float, reserved: float, damaged: float} */
    private function zeroBalance(): array
    {
        return ['available' => 0.0, 'reserved' => 0.0, 'damaged' => 0.0];
    }

    private function key(int $warehouseId, int $productId, ?int $variantId): string
    {
        return implode('|', [$warehouseId, $productId, $variantId === null ? 'null' : $variantId]);
    }
}
