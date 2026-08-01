<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla product_variants:
 *  - Representa cada combinacion vendible de un producto (hoy: color).
 *  - tenant_id + product_id es la llave natural. La variante "default" tiene
 *    color NULL y se crea automaticamente via seeder para no romper el flujo
 *    actual donde stock_balances / product_units / stock_movements apuntan
 *    solo a product_id.
 *  - sku_variant es UNIQUE por tenant cuando viene informado, para soportar
 *    lectores de codigo de barras por variante.
 *
 * Cross-DB: usamos foreignId + foreign() con llaves compuestas en lugar de
 * ->constrained()->... para que SQLite y Postgres acepten la misma firma
 * (SQLite no exige el nombre de la constraint compuesta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id');
            $table->string('color', 50)->nullable();
            $table->string('color_hex', 9)->nullable();
            $table->string('sku_variant', 100)->nullable();
            $table->string('barcode_variant', 100)->nullable();
            $table->decimal('price_override', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'is_active']);
            $table->index(['tenant_id', 'color']);
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
