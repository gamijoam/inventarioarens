<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_reversals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('pos_order_id');
            $table->unsignedBigInteger('cash_register_session_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('type', 20);
            $table->text('reason');
            $table->timestamp('original_paid_at')->nullable();
            $table->timestamp('effective_at');
            $table->decimal('reversed_base_amount', 18, 4)->default(0);
            $table->decimal('reversed_local_amount', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'pos_order_id'], 'sale_reversals_tenant_order_unique');
            $table->index(['tenant_id', 'effective_at']);
            $table->index(['tenant_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_reversals');
    }
};
