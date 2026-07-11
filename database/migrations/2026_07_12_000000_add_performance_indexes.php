<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function ($table) {
            $table->index(['tenant_id', 'confirmed_at'], 'sales_tenant_confirmed_at_idx');
        });

        Schema::table('pos_orders', function ($table) {
            $table->index(['tenant_id', 'paid_at'], 'pos_orders_tenant_paid_at_idx');
            $table->index(['tenant_id', 'opened_at'], 'pos_orders_tenant_opened_at_idx');
        });

        Schema::table('inventory_transfers', function ($table) {
            $table->index(['tenant_id', 'processed_at'], 'inv_transfers_tenant_processed_at_idx');
        });

        Schema::table('cash_register_sessions', function ($table) {
            $table->index(['tenant_id', 'opened_at'], 'crs_tenant_opened_at_idx');
        });

        Schema::table('warranty_claims', function ($table) {
            $table->index(['tenant_id', 'received_at'], 'wc_tenant_received_at_idx');
        });

        Schema::table('accounts_receivables', function ($table) {
            $table->index(['tenant_id', 'status', 'due_date'], 'ar_tenant_status_due_idx');
        });

        Schema::table('accounts_payables', function ($table) {
            $table->index(['tenant_id', 'status', 'due_date'], 'ap_tenant_status_due_idx');
        });

        Schema::table('stock_movements', function ($table) {
            $table->index(['tenant_id', 'product_id', 'created_at'], 'sm_tenant_product_date_idx');
            $table->index(['tenant_id', 'created_at'], 'sm_tenant_date_idx');
        });

        Schema::table('pos_payments', function ($table) {
            $table->index(['tenant_id', 'status'], 'pos_payments_tenant_status_idx');
        });

        Schema::table('sync_outbox', function ($table) {
            $table->index(['tenant_id', 'processed_at'], 'sync_outbox_tenant_processed_at_idx');
        });

        DB::statement('CREATE INDEX IF NOT EXISTS stock_balances_low_stock_idx ON stock_balances (tenant_id, product_id, warehouse_id) WHERE quantity_available > 0 AND quantity_available <= 10');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS exchange_rate_types_default_idx ON exchange_rate_types (tenant_id) WHERE is_default = true AND is_active = true');
    }

    public function down(): void
    {
        Schema::table('sales', function ($table) {
            $table->dropIndex('sales_tenant_confirmed_at_idx');
        });

        Schema::table('pos_orders', function ($table) {
            $table->dropIndex('pos_orders_tenant_paid_at_idx');
            $table->dropIndex('pos_orders_tenant_opened_at_idx');
        });

        Schema::table('inventory_transfers', function ($table) {
            $table->dropIndex('inv_transfers_tenant_processed_at_idx');
        });

        Schema::table('cash_register_sessions', function ($table) {
            $table->dropIndex('crs_tenant_opened_at_idx');
        });

        Schema::table('warranty_claims', function ($table) {
            $table->dropIndex('wc_tenant_received_at_idx');
        });

        Schema::table('accounts_receivables', function ($table) {
            $table->dropIndex('ar_tenant_status_due_idx');
        });

        Schema::table('accounts_payables', function ($table) {
            $table->dropIndex('ap_tenant_status_due_idx');
        });

        Schema::table('stock_movements', function ($table) {
            $table->dropIndex('sm_tenant_product_date_idx');
            $table->dropIndex('sm_tenant_date_idx');
        });

        Schema::table('pos_payments', function ($table) {
            $table->dropIndex('pos_payments_tenant_status_idx');
        });

        Schema::table('sync_outbox', function ($table) {
            $table->dropIndex('sync_outbox_tenant_processed_at_idx');
        });

        DB::statement('DROP INDEX IF EXISTS stock_balances_low_stock_idx');
        DB::statement('DROP INDEX IF EXISTS exchange_rate_types_default_idx');
    }
};
