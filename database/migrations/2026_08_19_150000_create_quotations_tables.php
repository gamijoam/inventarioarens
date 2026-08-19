<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('document_number');
            $table->foreignId('customer_id')->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->foreignId('warehouse_id')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal_base_amount', 18, 4)->default(0);
            $table->decimal('subtotal_local_amount', 18, 4)->default(0);
            $table->decimal('discount_base_amount', 18, 4)->default(0);
            $table->decimal('discount_local_amount', 18, 4)->default(0);
            $table->decimal('total_base_amount', 18, 4)->default(0);
            $table->decimal('total_local_amount', 18, 4)->default(0);
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code', 24)->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_pos_order_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->foreign(['tenant_id', 'warehouse_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouses')
                ->nullOnDelete();
        });

        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id');
            $table->foreignId('product_id');
            $table->foreignId('product_variant_id')->nullable();
            $table->string('product_name', 255);
            $table->string('sku', 255)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->foreignId('price_list_id')->nullable();
            $table->decimal('unit_price_base', 18, 4)->default(0);
            $table->decimal('unit_price_local', 18, 4)->default(0);
            $table->decimal('discount_base', 18, 4)->default(0);
            $table->decimal('discount_local', 18, 4)->default(0);
            $table->decimal('total_base', 18, 4)->default(0);
            $table->decimal('total_local', 18, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'quotation_id']);
            $table->foreign(['tenant_id', 'quotation_id'])
                ->references(['tenant_id', 'id'])
                ->on('quotations')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])
                ->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
