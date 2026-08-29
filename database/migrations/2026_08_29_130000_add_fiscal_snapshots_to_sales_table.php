<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('fiscal_taxable_base_amount', 18, 4)->default(0)->after('total_local_amount');
            $table->decimal('fiscal_taxable_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exempt_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exempt_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exonerated_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exonerated_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_non_taxable_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_non_taxable_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_tax_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_tax_local_amount', 18, 4)->default(0);
            $table->timestamp('fiscal_snapshot_at')->nullable();
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->decimal('fiscal_taxable_base_amount', 18, 4)->default(0)->after('total_local_amount');
            $table->decimal('fiscal_taxable_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exempt_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exempt_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exonerated_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_exonerated_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_non_taxable_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_non_taxable_local_amount', 18, 4)->default(0);
            $table->decimal('fiscal_tax_base_amount', 18, 4)->default(0);
            $table->decimal('fiscal_tax_local_amount', 18, 4)->default(0);
            $table->timestamp('fiscal_snapshot_at')->nullable();
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('local_total_amount', 18, 4)->default(0)->after('base_total_amount');
            $table->string('fiscal_tax_code', 50)->nullable()->after('local_total_amount');
            $table->string('fiscal_tax_name', 120)->nullable();
            $table->string('fiscal_tax_category', 20)->nullable();
            $table->decimal('fiscal_tax_rate', 8, 4)->nullable();
            $table->boolean('fiscal_prices_include_tax')->default(false);
            $table->decimal('fiscal_taxable_base_amount', 18, 4)->default(0);
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
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn([
                'local_total_amount',
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

        foreach (['pos_orders', 'sales'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn([
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
                    'fiscal_snapshot_at',
                ]);
            });
        }
    }
};
