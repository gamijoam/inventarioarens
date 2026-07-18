<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_payable_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounts_payable_id');
            $table->foreignId('accounts_payable_payment_id')->nullable();
            $table->string('status')->default('prepared');
            $table->string('payment_currency', 3)->default('USD');
            $table->decimal('amount', 18, 4);
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('amount_base', 18, 4);
            $table->decimal('amount_local', 18, 4);
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->foreignId('cash_register_session_id')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'accounts_payable_id']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'accounts_payable_id'])
                ->references(['tenant_id', 'id'])
                ->on('accounts_payables')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'accounts_payable_payment_id'])
                ->references(['tenant_id', 'id'])
                ->on('accounts_payable_payments')
                ->nullOnDelete();
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types');
            $table->foreign(['tenant_id', 'cash_register_session_id'])
                ->references(['tenant_id', 'id'])
                ->on('cash_register_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_payable_payment_requests');
    }
};
