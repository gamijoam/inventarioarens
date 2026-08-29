<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->string('fiscal_tax_source', 32)->default('product')->after('product_id');
            $table->string('fiscal_tax_override_code', 50)->nullable()->after('fiscal_tax_source');
            $table->string('fiscal_tax_code', 50)->nullable()->after('fiscal_tax_override_code');
            $table->string('fiscal_tax_name', 120)->nullable()->after('fiscal_tax_code');
            $table->string('fiscal_tax_category', 20)->nullable()->after('fiscal_tax_name');
            $table->decimal('fiscal_tax_rate', 8, 4)->nullable()->after('fiscal_tax_category');
            $table->boolean('fiscal_prices_include_tax')->default(false)->after('fiscal_tax_rate');
            $table->decimal('fiscal_taxable_base_amount', 18, 4)->default(0)->after('fiscal_prices_include_tax');
            $table->decimal('fiscal_taxable_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exempt_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exempt_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exonerated_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exonerated_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_non_taxable_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_non_taxable_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_tax_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_tax_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_total_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_total_local_amount', 18, 4)->default(0);
            $table->timestamp('fiscal_snapshot_at')->nullable();
            $table->index(['tenant_id', 'fiscal_tax_source']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'fiscal_tax_source']);
            $table->dropColumn([
                'fiscal_tax_source',
                'fiscal_tax_override_code',
                'fiscal_tax_code',
                'fiscal_tax_name',
                'fiscal_tax_category',
                'fiscal_tax_rate',
                'fiscal_prices_include_tax',
                'fiscal_taxable_base_amount',
                'fiscal_taxable_local_amount',
                'fiscal_exempt_base_amount',
                'fiscal_exempt_local_amount',
                'fiscal_exonerated_base_amount',
                'fiscal_exonerated_local_amount',
                'fiscal_non_taxable_base_amount',
                'fiscal_non_taxable_local_amount',
                'fiscal_tax_base_amount',
                'fiscal_tax_local_amount',
                'fiscal_total_base_amount',
                'fiscal_total_local_amount',
                'fiscal_snapshot_at',
            ]);
        });
    }
};
