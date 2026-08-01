<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega product_variant_id a las tablas de inventario.
 *  - stock_balances ahora resuelve UNIQUE por (tenant, warehouse, product, variant).
 *  - product_units (IMEI/seriales) queda asociado a la variante para que el scanner
 *    pueda validar que el IMEI pertenece al color elegido.
 *  - stock_movements queda con product_variant_id NULL para los movimientos
 *    historicos (no se recalculan hacia atras).
 *
 * Cross-DB: usamos DB::statement para dropear/recrear la UNIQUE porque SQLite
 * no soporta dropUnique() en migraciones de la misma forma que Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
            $table->index(['tenant_id', 'product_variant_id']);
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->nullOnDelete();
        });

        DB::statement('DROP INDEX IF EXISTS stock_balances_tenant_warehouse_product_unique');
        DB::statement('CREATE UNIQUE INDEX stock_balances_tenant_warehouse_product_variant_unique ON stock_balances (tenant_id, warehouse_id, product_id, product_variant_id)');

        Schema::table('product_units', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
            $table->index(['tenant_id', 'product_variant_id', 'status']);
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->nullOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
            $table->index(['tenant_id', 'product_variant_id']);
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
            $table->dropIndex(['tenant_id', 'product_variant_id']);
            $table->dropColumn('product_variant_id');
        });

        Schema::table('product_units', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
            $table->dropIndex(['tenant_id', 'product_variant_id', 'status']);
            $table->dropColumn('product_variant_id');
        });

        DB::statement('DROP INDEX IF EXISTS stock_balances_tenant_warehouse_product_variant_unique');
        DB::statement('CREATE UNIQUE INDEX stock_balances_tenant_warehouse_product_unique ON stock_balances (tenant_id, warehouse_id, product_id)');

        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
            $table->dropIndex(['tenant_id', 'product_variant_id']);
            $table->dropColumn('product_variant_id');
        });
    }
};
