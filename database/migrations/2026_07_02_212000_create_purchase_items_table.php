<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('product_id');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('total_cost', 18, 4);
            $table->decimal('base_unit_cost', 18, 4);
            $table->decimal('base_total_cost', 18, 4);
            $table->jsonb('serial_units')->nullable();
            $table->foreignId('stock_movement_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'purchase_order_id'])
                ->references(['tenant_id', 'id'])
                ->on('purchase_orders')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouses');
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products');
            $table->foreign(['tenant_id', 'stock_movement_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_movements');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
