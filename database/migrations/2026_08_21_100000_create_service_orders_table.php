<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('order_number');
            $table->string('type'); // repair | warranty
            $table->foreignId('warranty_claim_id')->nullable();
            $table->foreignId('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('device_description')->nullable();
            $table->text('issue_description')->nullable();
            $table->text('diagnosis')->nullable();
            $table->string('status')->default('received');
            $table->string('priority')->default('normal');
            $table->string('resolution')->nullable(); // workshop | exchange | return_supplier
            $table->foreignId('technician_id')->nullable();
            $table->foreignId('warehouse_id');
            $table->decimal('labor_base_amount', 18, 4)->default(0);
            $table->decimal('labor_local_amount', 18, 4)->default(0);
            $table->decimal('parts_base_amount', 18, 4)->default(0);
            $table->decimal('parts_local_amount', 18, 4)->default(0);
            $table->decimal('total_base_amount', 18, 4)->default(0);
            $table->decimal('total_local_amount', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('technician_assigned_at')->nullable();
            $table->timestamp('diagnosed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'technician_id']);
            $table->index(['tenant_id', 'warehouse_id']);
            $table->index(['tenant_id', 'type']);

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
