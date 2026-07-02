<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id');
            $table->string('status')->default('open');
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->decimal('total_base_amount', 18, 4)->default(0);
            $table->decimal('total_local_amount', 18, 4)->default(0);
            $table->decimal('paid_base_amount', 18, 4)->default(0);
            $table->decimal('paid_local_amount', 18, 4)->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])
                ->on('sales')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_orders');
    }
};
