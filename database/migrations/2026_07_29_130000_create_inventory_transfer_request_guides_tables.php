<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_request_guides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_transfer_request_id');
            $table->foreign('inventory_transfer_request_id', 'request_guide_request_fk')
                ->references('id')
                ->on('inventory_transfer_requests')
                ->cascadeOnDelete();
            $table->unique('inventory_transfer_request_id', 'request_guide_request_unique');
            $table->string('status', 40)->default('draft')->index();
            $table->string('carrier_name', 150)->nullable();
            $table->string('carrier_document_number', 50)->nullable();
            $table->string('carrier_phone', 50)->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->string('carrier_company', 150)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('difference_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_transfer_request_guide_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guide_id')
                ->constrained('inventory_transfer_request_guides')
                ->cascadeOnDelete();
            $table->foreignId('inventory_transfer_request_item_id')
                ->constrained('inventory_transfer_request_items')
                ->cascadeOnDelete();
            $table->decimal('prepared_quantity', 14, 4)->default(0);
            $table->decimal('received_quantity', 14, 4)->default(0);
            $table->json('prepared_serial_units')->nullable();
            $table->json('received_serial_units')->nullable();
            $table->string('difference_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['guide_id', 'inventory_transfer_request_item_id'], 'request_guide_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_request_guide_items');
        Schema::dropIfExists('inventory_transfer_request_guides');
    }
};
