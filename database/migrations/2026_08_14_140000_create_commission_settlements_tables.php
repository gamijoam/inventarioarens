<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('settlement_uuid');
            $table->foreignId('beneficiary_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->string('payment_currency', 3);
            $table->decimal('total_base_amount', 18, 4);
            $table->decimal('total_local_amount', 18, 4);
            $table->decimal('payment_amount', 18, 4);
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'settlement_uuid']);
            $table->index(['tenant_id', 'beneficiary_user_id', 'paid_at']);
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])->references(['tenant_id', 'id'])->on('exchange_rate_types')->nullOnDelete();
        });

        Schema::create('commission_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_settlement_id');
            $table->foreignId('commission_entry_id');
            $table->decimal('commission_base_amount', 18, 4);
            $table->timestamps();

            $table->unique(['tenant_id', 'commission_entry_id']);
            $table->foreign(['tenant_id', 'commission_settlement_id'])->references(['tenant_id', 'id'])->on('commission_settlements')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'commission_entry_id'])->references(['tenant_id', 'id'])->on('commission_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settlement_items');
        Schema::dropIfExists('commission_settlements');
    }
};
