<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id');
            $table->foreignId('category_id');

            $table->unique(['tenant_id', 'product_id', 'category_id'], 'product_category_unique');
            $table->index(['tenant_id', 'category_id']);
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'category_id'])
                ->references(['tenant_id', 'id'])
                ->on('categories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category');
    }
};