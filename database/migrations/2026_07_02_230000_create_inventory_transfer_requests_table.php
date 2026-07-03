<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sequence');
            $table->string('document_number')->unique();
            $table->foreignId('origin_tenant_id')->constrained('tenants');
            $table->foreignId('destination_tenant_id')->constrained('tenants');
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses');
            $table->string('status')->default('requested');
            $table->string('reason')->nullable();
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->text('response_notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['origin_tenant_id', 'sequence']);
            $table->index(['origin_tenant_id', 'status']);
            $table->index(['destination_tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_requests');
    }
};
