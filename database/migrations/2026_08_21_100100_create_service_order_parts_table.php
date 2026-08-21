<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('service_order_id');
            $table->foreignId('product_id');
            $table->foreignId('product_variant_id')->nullable();
            $table->foreignId('warehouse_id');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->decimal('base_unit_price', 18, 4)->nullable();
            $table->decimal('base_unit_cost', 18, 4)->nullable();
            $table->foreignId('stock_movement_id')->nullable();
            $table->string('status')->default('pending'); // pending | consumed | returned
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'service_order_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'status']);

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'service_order_id'])
                ->references(['tenant_id', 'id'])
                ->on('service_orders')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products');
            $table->foreign(['tenant_id', 'warehouse_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_parts');
    }
};
