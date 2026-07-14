<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_count_id');
            $table->foreignId('product_id');
            $table->foreignId('location_id')->nullable();
            $table->decimal('system_quantity', 18, 4)->default(0);
            $table->decimal('counted_quantity', 18, 4)->nullable();
            $table->decimal('variance', 18, 4)->nullable();
            $table->string('status', 20)->default('pending'); // pending|counted|adjusted
            $table->text('notes')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'stock_count_id', 'product_id', 'location_id'], 'stock_count_items_unique');
            $table->index(['tenant_id', 'stock_count_id', 'status']);
            $table->foreign(['tenant_id', 'stock_count_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_counts')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'location_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouse_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
    }
};