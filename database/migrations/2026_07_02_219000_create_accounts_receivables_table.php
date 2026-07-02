<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_receivables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('sale_id');
            $table->string('status')->default('pending');
            $table->string('document_number')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('original_base_amount', 18, 4)->default(0);
            $table->decimal('original_local_amount', 18, 4)->default(0);
            $table->decimal('returned_base_amount', 18, 4)->default(0);
            $table->decimal('returned_local_amount', 18, 4)->default(0);
            $table->decimal('collected_base_amount', 18, 4)->default(0);
            $table->decimal('collected_local_amount', 18, 4)->default(0);
            $table->decimal('balance_base_amount', 18, 4)->default(0);
            $table->decimal('balance_local_amount', 18, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on('customers');
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])
                ->on('sales')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_receivables');
    }
};
