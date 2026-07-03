<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('document_number');
            $table->string('account_type', 30);
            $table->foreignId('accounts_receivable_id')->nullable();
            $table->foreignId('accounts_payable_id')->nullable();
            $table->string('status', 30)->default('applied');
            $table->string('currency', 3);
            $table->decimal('amount', 18, 4);
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('amount_base', 18, 4);
            $table->decimal('amount_local', 18, 4);
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sequence']);
            $table->unique(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'account_type', 'status']);
            $table->foreign(['tenant_id', 'accounts_receivable_id'])
                ->references(['tenant_id', 'id'])
                ->on('accounts_receivables')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'accounts_payable_id'])
                ->references(['tenant_id', 'id'])
                ->on('accounts_payables')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_adjustments');
    }
};
