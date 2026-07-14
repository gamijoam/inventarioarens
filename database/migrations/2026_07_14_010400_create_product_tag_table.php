<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id');
            $table->foreignId('tag_id');

            $table->unique(['tenant_id', 'product_id', 'tag_id'], 'product_tag_unique');
            $table->index(['tenant_id', 'tag_id']);
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'tag_id'])
                ->references(['tenant_id', 'id'])
                ->on('tags')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tag');
    }
};