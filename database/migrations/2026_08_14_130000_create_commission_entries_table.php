<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('entry_uuid');
            $table->foreignId('commission_plan_id')->nullable();
            $table->foreignId('sale_id')->nullable();
            $table->foreignId('pos_order_id')->nullable();
            $table->foreignId('sale_item_id')->nullable();
            $table->foreignId('accounts_receivable_payment_id')->nullable();
            $table->foreignId('sales_return_id')->nullable();
            $table->foreignId('beneficiary_user_id')->constrained('users')->restrictOnDelete();
            $table->string('beneficiary_role');
            $table->string('entry_type');
            $table->foreignId('original_entry_id')->nullable();
            $table->string('plan_name_snapshot');
            $table->decimal('percentage_snapshot', 7, 4);
            $table->string('sale_currency', 3);
            $table->decimal('source_amount', 18, 4);
            $table->decimal('eligible_base_amount', 18, 4);
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('commission_base_amount', 18, 4);
            $table->string('status')->default('pending');
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('earned_at');
            $table->timestamp('available_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'entry_uuid']);
            $table->unique(
                ['tenant_id', 'commission_plan_id', 'sale_item_id', 'beneficiary_user_id', 'entry_type', 'accounts_receivable_payment_id', 'sales_return_id'],
                'commission_entries_source_unique'
            );
            $table->index(['tenant_id', 'beneficiary_user_id', 'status', 'earned_at']);
            $table->foreign(['tenant_id', 'commission_plan_id'])->references(['tenant_id', 'id'])->on('commission_plans')->nullOnDelete();
            $table->foreign(['tenant_id', 'sale_id'])->references(['tenant_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pos_order_id'])->references(['tenant_id', 'id'])->on('pos_orders')->nullOnDelete();
            $table->foreign(['tenant_id', 'sale_item_id'])->references(['tenant_id', 'id'])->on('sale_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accounts_receivable_payment_id'])->references(['tenant_id', 'id'])->on('accounts_receivable_payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sales_return_id'])->references(['tenant_id', 'id'])->on('sales_returns')->restrictOnDelete();
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])->references(['tenant_id', 'id'])->on('exchange_rate_types')->nullOnDelete();
            $table->foreign(['tenant_id', 'original_entry_id'])->references(['tenant_id', 'id'])->on('commission_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
    }
};
