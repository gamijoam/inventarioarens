<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_return_id');
            $table->foreignId('sale_item_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('product_id');
            $table->decimal('quantity', 18, 4);
            $table->jsonb('product_unit_ids')->nullable();
            $table->foreignId('stock_movement_id')->nullable();
            $table->string('condition')->default('sellable');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'sales_return_id'])
                ->references(['tenant_id', 'id'])
                ->on('sales_returns')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sale_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('sale_items');
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
        Schema::dropIfExists('sales_return_items');
    }
};
