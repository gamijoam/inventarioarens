<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_payables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable();
            $table->foreignId('purchase_order_id');
            $table->string('status')->default('pending');
            $table->string('document_number')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('original_base_amount', 18, 4)->default(0);
            $table->decimal('original_local_amount', 18, 4)->default(0);
            $table->decimal('returned_base_amount', 18, 4)->default(0);
            $table->decimal('returned_local_amount', 18, 4)->default(0);
            $table->decimal('paid_base_amount', 18, 4)->default(0);
            $table->decimal('paid_local_amount', 18, 4)->default(0);
            $table->decimal('balance_base_amount', 18, 4)->default(0);
            $table->decimal('balance_local_amount', 18, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'purchase_order_id']);
            $table->index(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'supplier_id'])
                ->references(['tenant_id', 'id'])
                ->on('suppliers');
            $table->foreign(['tenant_id', 'purchase_order_id'])
                ->references(['tenant_id', 'id'])
                ->on('purchase_orders')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_payables');
    }
};
