<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vencimientos y lotes: existencia por lote con fecha de vencimiento para los
 * productos que controlan vencimiento, y la imputacion de cada movimiento a
 * los lotes que tomo o repuso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('tracks_expiration')->default(false);
        });

        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id');
            $table->foreignId('product_id');
            $table->foreignId('product_variant_id')->nullable();
            $table->string('lot_code', 100)->nullable();
            $table->date('expires_on');
            $table->string('lot_key', 160);
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'warehouse_id', 'product_id', 'lot_key'], 'stock_lots_identity_unique');
            $table->index(['tenant_id', 'expires_on']);
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });

        Schema::create('stock_lot_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_lot_id');
            $table->foreignId('stock_movement_id');
            $table->decimal('quantity', 18, 4);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'stock_movement_id']);
            $table->foreign(['tenant_id', 'stock_lot_id'])->references(['tenant_id', 'id'])->on('stock_lots')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_lot_movements');
        Schema::dropIfExists('stock_lots');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('tracks_expiration');
        });
    }
};
