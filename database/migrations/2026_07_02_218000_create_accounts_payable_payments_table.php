<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_payable_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounts_payable_id');
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
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'accounts_payable_id']);
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
        Schema::dropIfExists('accounts_payable_payments');
    }
};
