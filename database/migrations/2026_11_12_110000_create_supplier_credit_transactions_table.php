<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credito con proveedor (append-only): devoluciones posteriores al pago
 * generan saldo a favor que se aplica a otra CxP o se cobra como reembolso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('sync_source_node_code')->nullable();
            $table->unsignedBigInteger('sync_source_id')->nullable();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id');
            $table->string('type', 30);
            $table->string('currency', 3);
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4);
            $table->decimal('amount_local', 18, 4);
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->foreignId('accounts_payable_id')->nullable();
            $table->foreignId('accounts_payable_payment_id')->nullable();
            $table->foreignId('purchase_return_id')->nullable();
            $table->string('method', 100)->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'supplier_credit_sync_source_unique');
            $table->unique(['tenant_id', 'type', 'purchase_return_id'], 'supplier_credit_return_unique');
            $table->index(['tenant_id', 'supplier_id']);
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers');
            $table->foreign(['tenant_id', 'accounts_payable_id'])->references(['tenant_id', 'id'])->on('accounts_payables');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_transactions');
    }
};
