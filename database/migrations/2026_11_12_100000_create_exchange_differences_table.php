<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro de diferencial cambiario: ganancia (positivo) o perdida (negativo) al
 * cobrar o pagar a una tasa distinta a la del documento. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_differences', function (Blueprint $table): void {
            $table->id();
            $table->string('sync_source_node_code')->nullable();
            $table->unsignedBigInteger('sync_source_id')->nullable();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('account_type', 20);
            $table->foreignId('accounts_receivable_id')->nullable();
            $table->foreignId('accounts_payable_id')->nullable();
            $table->string('document_number')->nullable();
            $table->string('payment_type')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('document_currency', 3);
            $table->string('payment_currency', 3);
            $table->decimal('document_rate', 18, 6);
            $table->decimal('settlement_rate', 18, 6);
            $table->decimal('applied_base_amount', 18, 4);
            $table->decimal('applied_local_amount', 18, 4);
            $table->decimal('paid_base_amount', 18, 4);
            $table->decimal('paid_local_amount', 18, 4);
            $table->decimal('difference_base_amount', 18, 4);
            $table->decimal('difference_local_amount', 18, 4);
            $table->string('kind', 10);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'exchange_differences_sync_source_unique');
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'account_type']);
            $table->foreign(['tenant_id', 'accounts_receivable_id'])->references(['tenant_id', 'id'])->on('accounts_receivables');
            $table->foreign(['tenant_id', 'accounts_payable_id'])->references(['tenant_id', 'id'])->on('accounts_payables');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_differences');
    }
};
