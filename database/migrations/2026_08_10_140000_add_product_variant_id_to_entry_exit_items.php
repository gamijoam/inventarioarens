<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega product_variant_id a product_entry_items y product_exit_items.
 *
 * El sync de compras (purchase_order.received) y de entradas/salidas manuales
 * replica el stock por variante (presentacion/color) usando `product_variant_id`.
 * Estas tablas de detalle deben guardar la variante para que los reportes de
 * entradas/salidas muestren la presentacion correcta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_entry_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
            $table->index(['tenant_id', 'product_variant_id']);
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->nullOnDelete();
        });

        Schema::table('product_exit_items', function (Blueprint $table): void {
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
        Schema::table('product_exit_items', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
            $table->dropIndex(['tenant_id', 'product_variant_id']);
            $table->dropColumn('product_variant_id');
        });

        Schema::table('product_entry_items', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
            $table->dropIndex(['tenant_id', 'product_variant_id']);
            $table->dropColumn('product_variant_id');
        });
    }
};
