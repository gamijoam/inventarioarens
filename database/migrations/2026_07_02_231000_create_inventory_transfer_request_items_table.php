<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_transfer_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_product_id')->constrained('products');
            $table->foreignId('destination_product_id')->nullable()->constrained('products');
            $table->decimal('quantity', 18, 4);
            $table->json('product_unit_ids')->nullable();
            $table->json('serial_units')->nullable();
            $table->foreignId('out_stock_movement_id')->nullable()->constrained('stock_movements');
            $table->foreignId('in_stock_movement_id')->nullable()->constrained('stock_movements');
            $table->timestamps();

            $table->index(['inventory_transfer_request_id', 'origin_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_request_items');
    }
};
