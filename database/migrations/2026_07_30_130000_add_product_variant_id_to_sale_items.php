<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega product_variant_id a sale_items para registrar el color/variante
 * vendido en cada linea de venta POS. Es nullable para mantener
 * compatibilidad con ventas legacy (sin variante asignada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
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
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
            $table->dropIndex(['tenant_id', 'product_variant_id']);
            $table->dropColumn('product_variant_id');
        });
    }
};
