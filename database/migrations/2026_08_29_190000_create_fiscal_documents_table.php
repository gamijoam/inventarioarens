<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id');
            $table->string('document_type', 40);
            $table->string('document_mode', 40);
            $table->string('status', 30);
            $table->json('company_snapshot');
            $table->json('branch_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->json('totals_snapshot');
            $table->timestamp('snapshot_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sale_id', 'document_type']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'sale_id'])
                ->references(['tenant_id', 'id'])
                ->on('sales')
                ->cascadeOnDelete();
        });

        Schema::create('fiscal_document_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_document_id');
            $table->foreignId('sale_item_id');
            $table->decimal('quantity', 18, 4);
            $table->string('sale_currency', 3);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('total_amount', 18, 4);
            $table->decimal('base_unit_price', 18, 4);
            $table->decimal('base_total_amount', 18, 4);
            $table->decimal('local_total_amount', 18, 4)->default(0);
            $table->json('product_snapshot');
            $table->json('warehouse_snapshot')->nullable();
            $table->json('commercial_snapshot')->nullable();
            $table->json('fiscal_snapshot');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'fiscal_document_id', 'sale_item_id']);
            $table->foreign(['tenant_id', 'fiscal_document_id'])
                ->references(['tenant_id', 'id'])
                ->on('fiscal_documents')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sale_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('sale_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_items');
        Schema::dropIfExists('fiscal_documents');
    }
};
