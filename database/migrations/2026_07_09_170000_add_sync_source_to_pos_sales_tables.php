<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'sales_sync_source_unique');
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->string('sync_branch_name')->nullable()->after('customer_name');
            $table->string('sync_cash_register_name')->nullable()->after('sync_branch_name');
            $table->string('sync_cashier_name')->nullable()->after('sync_cash_register_name');
            $table->string('sync_customer_document_type', 20)->nullable()->after('sync_cashier_name');
            $table->string('sync_customer_document_number')->nullable()->after('sync_customer_document_type');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'pos_orders_sync_source_unique');
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'sale_items_sync_source_unique');
        });

        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'pos_payments_sync_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->dropUnique('pos_payments_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropUnique('sale_items_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropUnique('pos_orders_sync_source_unique');
            $table->dropColumn([
                'sync_source_node_code',
                'sync_source_id',
                'sync_branch_name',
                'sync_cash_register_name',
                'sync_cashier_name',
                'sync_customer_document_type',
                'sync_customer_document_number',
            ]);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropUnique('sales_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });
    }
};
