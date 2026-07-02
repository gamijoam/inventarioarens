<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable();
            $table->string('status')->default('draft');
            $table->string('document_number')->nullable();
            $table->string('purchase_currency', 3)->default('USD');
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('total_base_amount', 18, 4)->default(0);
            $table->decimal('total_local_amount', 18, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'supplier_id'])
                ->references(['tenant_id', 'id'])
                ->on('suppliers');
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
