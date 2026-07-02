<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_order_id');
            $table->string('method');
            $table->string('currency', 3);
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4);
            $table->decimal('amount_local', 18, 4)->nullable();
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->string('status')->default('captured');
            $table->string('reference')->nullable();
            $table->string('external_provider')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'pos_order_id']);
            $table->index(['tenant_id', 'method']);
            $table->foreign(['tenant_id', 'pos_order_id'])
                ->references(['tenant_id', 'id'])
                ->on('pos_orders')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
    }
};
