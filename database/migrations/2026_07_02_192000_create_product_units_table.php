<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id');
            $table->foreignId('warehouse_id')->nullable();
            $table->string('serial_type')->default('serial');
            $table->string('serial_number');
            $table->string('status')->default('available');
            $table->foreignId('acquired_stock_movement_id')->nullable();
            $table->foreignId('released_stock_movement_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'serial_type', 'serial_number']);
            $table->index(['tenant_id', 'product_id', 'status']);
            $table->index(['tenant_id', 'warehouse_id', 'status']);
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouses')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'acquired_stock_movement_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_movements');
            $table->foreign(['tenant_id', 'released_stock_movement_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_movements');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
