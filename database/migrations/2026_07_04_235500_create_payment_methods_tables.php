<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('method');
            $table->string('currency_mode', 8)->default('flexible');
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'method', 'is_active']);
            $table->index(['tenant_id', 'currency_mode', 'is_active']);
        });

        Schema::create('price_list_payment_method', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id');
            $table->foreignId('payment_method_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'price_list_id', 'payment_method_id'], 'plpm_unique');
            $table->index(['tenant_id', 'payment_method_id']);
            $table->foreign(['tenant_id', 'price_list_id'])
                ->references(['tenant_id', 'id'])
                ->on('price_lists')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'payment_method_id'])
                ->references(['tenant_id', 'id'])
                ->on('payment_methods')
                ->cascadeOnDelete();
        });

        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')->nullable()->after('pos_order_id');
            $table->foreign(['tenant_id', 'payment_method_id'])
                ->references(['tenant_id', 'id'])
                ->on('payment_methods')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'payment_method_id']);
            $table->dropColumn('payment_method_id');
        });

        Schema::dropIfExists('price_list_payment_method');
        Schema::dropIfExists('payment_methods');
    }
};
