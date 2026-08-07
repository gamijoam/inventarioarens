<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('benefit_type');
            $table->string('price_currency', 3)->default('USD');
            $table->decimal('price_usd', 18, 4)->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active', 'priority']);
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id');
            $table->foreignId('product_id');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'promotion_id', 'product_id']);
            $table->foreign(['tenant_id', 'promotion_id'])
                ->references(['tenant_id', 'id'])
                ->on('promotions')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('promotion_id')->nullable()->after('price_list_id');
            $table->string('promotion_code')->nullable()->after('promotion_id');
            $table->string('promotion_name')->nullable()->after('promotion_code');
            $table->string('promotion_benefit_type')->nullable()->after('promotion_name');
            $table->decimal('promotion_price_usd', 18, 4)->nullable()->after('promotion_benefit_type');
            $table->decimal('promotion_adjustment_base_amount', 18, 4)->default(0)->after('promotion_price_usd');
            $table->decimal('promotion_adjustment_local_amount', 18, 4)->default(0)->after('promotion_adjustment_base_amount');

            $table->foreign(['tenant_id', 'promotion_id'])
                ->references(['tenant_id', 'id'])
                ->on('promotions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'promotion_id']);
            $table->dropColumn([
                'promotion_id',
                'promotion_code',
                'promotion_name',
                'promotion_benefit_type',
                'promotion_price_usd',
                'promotion_adjustment_base_amount',
                'promotion_adjustment_local_amount',
            ]);
        });

        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotions');
    }
};
