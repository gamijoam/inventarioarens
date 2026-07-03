<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('document_number');
            $table->string('type')->default('internal');
            $table->foreignId('from_warehouse_id');
            $table->foreignId('to_warehouse_id');
            $table->string('status')->default('completed');
            $table->string('reason')->nullable();
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sequence']);
            $table->unique(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'from_warehouse_id']);
            $table->index(['tenant_id', 'to_warehouse_id']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id', 'from_warehouse_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouses');
            $table->foreign(['tenant_id', 'to_warehouse_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
