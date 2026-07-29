<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->foreignId('customer_credit_transaction_id')->nullable()->after('refund_financial_adjustment_id');
        });

        Schema::create('customer_credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->string('type', 30);
            $table->string('currency', 3);
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4);
            $table->decimal('amount_local', 18, 4);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on('customers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_transactions');

        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropColumn('customer_credit_transaction_id');
        });
    }
};
