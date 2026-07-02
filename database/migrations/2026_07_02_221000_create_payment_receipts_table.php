<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('receipt_number');
            $table->string('type', 50);
            $table->string('status', 30)->default('issued');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('accounts_receivable_payment_id')->nullable();
            $table->foreignId('accounts_payable_payment_id')->nullable();
            $table->string('party_type', 30);
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('party_name')->nullable();
            $table->string('party_document_type')->nullable();
            $table->string('party_document_number')->nullable();
            $table->string('payment_currency', 3);
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4);
            $table->decimal('amount_local', 18, 4);
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sequence']);
            $table->unique(['tenant_id', 'receipt_number']);
            $table->unique(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'type', 'status']);
            $table->index(['tenant_id', 'issued_at']);
            $table->foreign(['tenant_id', 'accounts_receivable_payment_id'])
                ->references(['tenant_id', 'id'])
                ->on('accounts_receivable_payments')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'accounts_payable_payment_id'])
                ->references(['tenant_id', 'id'])
                ->on('accounts_payable_payments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
