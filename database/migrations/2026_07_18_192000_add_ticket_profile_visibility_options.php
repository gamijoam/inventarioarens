<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_profiles', function (Blueprint $table): void {
            $table->boolean('show_tenant_slug')->default(true)->after('logo_text');
            $table->boolean('show_sale_number')->default(true)->after('show_tenant_slug');
            $table->boolean('show_paid_at')->default(true)->after('show_sale_number');
            $table->boolean('show_cashier')->default(true)->after('show_paid_at');
            $table->boolean('show_cash_register')->default(true)->after('show_cashier');
            $table->boolean('show_branch')->default(true)->after('show_cash_register');
            $table->boolean('show_customer')->default(true)->after('show_branch');
            $table->boolean('show_item_sku')->default(true)->after('show_customer');
            $table->boolean('show_item_discount')->default(true)->after('show_item_sku');
            $table->boolean('show_item_serials')->default(true)->after('show_item_discount');
            $table->boolean('show_total_local')->default(true)->after('show_warranty_summary');
            $table->boolean('show_payment_rate')->default(true)->after('show_total_local');
            $table->boolean('show_payment_reference')->default(true)->after('show_payment_rate');
            $table->boolean('show_receivable_balance')->default(true)->after('show_payment_reference');
            $table->boolean('show_non_fiscal_text')->default(true)->after('show_receivable_balance');
            $table->text('warranty_policy_text')->nullable()->after('footer_text');
            $table->string('legal_text')->nullable()->default('Documento no fiscal')->after('warranty_policy_text');
        });
    }

    public function down(): void
    {
        Schema::table('print_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'show_tenant_slug',
                'show_sale_number',
                'show_paid_at',
                'show_cashier',
                'show_cash_register',
                'show_branch',
                'show_customer',
                'show_item_sku',
                'show_item_discount',
                'show_item_serials',
                'show_total_local',
                'show_payment_rate',
                'show_payment_reference',
                'show_receivable_balance',
                'show_non_fiscal_text',
                'warranty_policy_text',
                'legal_text',
            ]);
        });
    }
};
