<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_requests', function (Blueprint $table): void {
            $table->string('flow_type', 32)->default('stock_request')->after('status');
            $table->foreignId('initiated_by_tenant_id')->nullable()->after('destination_tenant_id')->constrained('tenants');
            $table->foreignId('sender_tenant_id')->nullable()->after('initiated_by_tenant_id')->constrained('tenants');
            $table->foreignId('receiver_tenant_id')->nullable()->after('sender_tenant_id')->constrained('tenants');
            $table->foreignId('sender_warehouse_id')->nullable()->after('destination_warehouse_id')->constrained('warehouses');
            $table->foreignId('receiver_warehouse_id')->nullable()->after('sender_warehouse_id')->constrained('warehouses');
            $table->index(['sender_tenant_id', 'status']);
            $table->index(['receiver_tenant_id', 'status']);
        });

        DB::table('inventory_transfer_requests')->update([
            'flow_type' => 'stock_request',
            'initiated_by_tenant_id' => DB::raw('origin_tenant_id'),
            'sender_tenant_id' => DB::raw('destination_tenant_id'),
            'receiver_tenant_id' => DB::raw('origin_tenant_id'),
            'sender_warehouse_id' => DB::raw('destination_warehouse_id'),
            'receiver_warehouse_id' => DB::raw('from_warehouse_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_requests', function (Blueprint $table): void {
            $table->dropIndex(['sender_tenant_id', 'status']);
            $table->dropIndex(['receiver_tenant_id', 'status']);
            $table->dropConstrainedForeignId('receiver_warehouse_id');
            $table->dropConstrainedForeignId('sender_warehouse_id');
            $table->dropConstrainedForeignId('receiver_tenant_id');
            $table->dropConstrainedForeignId('sender_tenant_id');
            $table->dropConstrainedForeignId('initiated_by_tenant_id');
            $table->dropColumn('flow_type');
        });
    }
};
