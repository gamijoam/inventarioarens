<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_exit_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_exit_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('product_id');
            $table->decimal('quantity', 18, 4);
            $table->foreignId('stock_movement_id')->nullable();
            $table->json('product_unit_ids')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'product_exit_id']);
            $table->index(['tenant_id', 'warehouse_id', 'product_id']);
            $table->foreign(['tenant_id', 'product_exit_id'])
                ->references(['tenant_id', 'id'])
                ->on('product_exits')
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
        Schema::dropIfExists('product_exit_items');
    }
};
