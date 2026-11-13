<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presentaciones de un producto (caja, bulto, paquete): factor en unidades,
 * codigo de barras propio y precio propio opcional en USD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_packagings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id');
            $table->foreignId('product_variant_id')->nullable();
            $table->string('name', 120);
            $table->decimal('factor', 18, 4);
            $table->string('barcode', 120)->nullable();
            $table->decimal('price_usd', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'product_id', 'name'], 'product_packagings_name_unique');
            $table->unique(['tenant_id', 'barcode'], 'product_packagings_barcode_unique');
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->foreignId('product_packaging_id')->nullable();
            $table->decimal('packaging_quantity', 18, 4)->nullable();
            $table->decimal('packaging_cost', 18, 4)->nullable();
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('product_packaging_id')->nullable();
            $table->decimal('packaging_quantity', 18, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn(['product_packaging_id', 'packaging_quantity']);
        });
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropColumn(['product_packaging_id', 'packaging_quantity', 'packaging_cost']);
        });
        Schema::dropIfExists('product_packagings');
    }
};
