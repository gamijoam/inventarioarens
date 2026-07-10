<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->string('resolution_status')->default('unresolved')->after('cancelled_by');
            $table->text('resolution_notes')->nullable()->after('resolution_status');
            $table->timestamp('resolved_at')->nullable()->after('resolution_notes');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'resolution_status']);
        });

        Schema::table('inventory_transfer_items', function (Blueprint $table): void {
            $table->string('resolution_status')->default('unresolved')->after('received_product_unit_ids');
            $table->text('resolution_notes')->nullable()->after('resolution_status');
            $table->timestamp('resolved_at')->nullable()->after('resolution_notes');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'resolution_notes', 'resolution_status']);
        });

        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'resolution_status']);
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'resolution_notes', 'resolution_status']);
        });
    }
};
