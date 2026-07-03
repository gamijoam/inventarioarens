<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id');
            $table->foreignId('sale_item_id');
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('product_id');
            $table->foreignId('product_unit_id')->nullable();
            $table->string('status')->default('received');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('issue_description');
            $table->text('received_notes')->nullable();
            $table->text('diagnosis')->nullable();
            $table->string('resolution_type')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('received_by');
            $table->foreignId('reviewed_by')->nullable();
            $table->foreignId('delivered_by')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sale_item_id']);
            $table->index(['tenant_id', 'product_unit_id']);
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])
                ->on('sales')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sale_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('sale_items')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on('customers');
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products');
            $table->foreign('product_unit_id')
                ->references('id')
                ->on('product_units');
            $table->foreign('received_by')->references('id')->on('users');
            $table->foreign('reviewed_by')->references('id')->on('users');
            $table->foreign('delivered_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
