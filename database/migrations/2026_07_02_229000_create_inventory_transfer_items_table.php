<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_transfer_id');
            $table->foreignId('product_id');
            $table->decimal('quantity', 18, 4);
            $table->foreignId('out_stock_movement_id')->nullable();
            $table->foreignId('in_stock_movement_id')->nullable();
            $table->json('product_unit_ids')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'inventory_transfer_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'inventory_transfer_id'])
                ->references(['tenant_id', 'id'])
                ->on('inventory_transfers')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products');
            $table->foreign(['tenant_id', 'out_stock_movement_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_movements');
            $table->foreign(['tenant_id', 'in_stock_movement_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_movements');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
    }
};
