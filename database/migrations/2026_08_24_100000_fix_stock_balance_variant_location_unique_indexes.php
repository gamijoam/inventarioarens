<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->mergeDuplicateBalances();

            foreach ([
                'stock_balances_unique_no_location',
                'stock_balances_unique_with_location',
                'stock_balances_tenant_warehouse_product_unique',
                'stock_balances_tenant_warehouse_product_variant_unique',
            ] as $index) {
                DB::statement("DROP INDEX IF EXISTS {$index}");
            }

            DB::statement(
                'CREATE UNIQUE INDEX stock_balances_variant_no_location_unique '
                .'ON stock_balances (tenant_id, warehouse_id, product_id, product_variant_id) '
                .'WHERE location_id IS NULL',
            );
            DB::statement(
                'CREATE UNIQUE INDEX stock_balances_variant_location_unique '
                .'ON stock_balances (tenant_id, warehouse_id, location_id, product_id, product_variant_id) '
                .'WHERE location_id IS NOT NULL',
            );
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stock_balances_variant_location_unique');
        DB::statement('DROP INDEX IF EXISTS stock_balances_variant_no_location_unique');

        DB::statement(
            'CREATE UNIQUE INDEX stock_balances_tenant_warehouse_product_variant_unique '
            .'ON stock_balances (tenant_id, warehouse_id, product_id, product_variant_id)',
        );
    }

    private function mergeDuplicateBalances(): void
    {
        $groups = DB::table('stock_balances')
            ->select(['tenant_id', 'warehouse_id', 'location_id', 'product_id', 'product_variant_id'])
            ->groupBy(['tenant_id', 'warehouse_id', 'location_id', 'product_id', 'product_variant_id'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $query = DB::table('stock_balances')
                ->where('tenant_id', $group->tenant_id)
                ->where('warehouse_id', $group->warehouse_id)
                ->where('product_id', $group->product_id);

            $this->whereNullable($query, 'location_id', $group->location_id);
            $this->whereNullable($query, 'product_variant_id', $group->product_variant_id);

            $balances = $query->orderBy('id')->get();
            $canonical = $balances->first();

            if ($canonical === null) {
                continue;
            }

            DB::table('stock_balances')
                ->where('id', $canonical->id)
                ->update([
                    'quantity_available' => $balances->sum(fn ($balance): float => (float) $balance->quantity_available),
                    'quantity_reserved' => $balances->sum(fn ($balance): float => (float) $balance->quantity_reserved),
                    'quantity_damaged' => $balances->sum(fn ($balance): float => (float) $balance->quantity_damaged),
                    'updated_at' => now(),
                ]);

            DB::table('stock_balances')
                ->whereIn('id', $balances->skip(1)->pluck('id')->all())
                ->delete();
        }
    }

    private function whereNullable(Builder $query, string $column, mixed $value): void
    {
        $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }
};
