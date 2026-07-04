<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active', 'is_default']);
        });

        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id');
            $table->foreignId('price_list_id');
            $table->decimal('price', 18, 4);
            $table->string('currency', 3)->default('USD');
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'product_id', 'price_list_id']);
            $table->index(['tenant_id', 'price_list_id', 'is_active']);
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'price_list_id'])
                ->references(['tenant_id', 'id'])
                ->on('price_lists')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types')
                ->nullOnDelete();
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('price_list_id')->nullable()->after('product_id');
            $table->string('price_list_name')->nullable()->after('price_list_id');
            $table->foreign(['tenant_id', 'price_list_id'])
                ->references(['tenant_id', 'id'])
                ->on('price_lists')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'price_list_id']);
            $table->dropColumn(['price_list_id', 'price_list_name']);
        });

        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('price_lists');
    }
};
