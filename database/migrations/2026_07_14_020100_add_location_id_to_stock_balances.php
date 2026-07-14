<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('product_id');

            $table->index(['tenant_id', 'warehouse_id', 'location_id', 'product_id'], 'stock_balances_location_idx');
            $table->index(['tenant_id', 'location_id'], 'stock_balances_tenant_location_idx');
            $table->foreign(['tenant_id', 'location_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouse_locations')
                ->nullOnDelete();
        });

        // Reemplazar el UNIQUE actual (warehouse_id, product_id) por uno condicional
        // que solo aplique cuando location_id IS NULL (compatibilidad hacia atras).
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'warehouse_id', 'product_id']);
        });

        // PostgreSQL: índice UNIQUE parcial.
        DB::statement('CREATE UNIQUE INDEX stock_balances_unique_no_location ON stock_balances (tenant_id, warehouse_id, product_id) WHERE location_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX stock_balances_unique_with_location ON stock_balances (tenant_id, warehouse_id, location_id, product_id) WHERE location_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stock_balances_unique_with_location');
        DB::statement('DROP INDEX IF EXISTS stock_balances_unique_no_location');

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'location_id']);
            $table->dropIndex('stock_balances_location_idx');
            $table->dropIndex('stock_balances_tenant_location_idx');
            $table->dropColumn('location_id');
            $table->unique(['tenant_id', 'warehouse_id', 'product_id']);
        });
    }
};
