<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->string('fiscal_tax_mode', 32)->default('inherit_product_tax')->after('allows_combos');
            $table->unsignedBigInteger('fiscal_tax_rate_id')->nullable()->after('fiscal_tax_mode');
            $table->index(['tenant_id', 'fiscal_tax_mode']);
            $table->foreign(['tenant_id', 'fiscal_tax_rate_id'])
                ->references(['tenant_id', 'id'])
                ->on('fiscal_tax_rates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'fiscal_tax_rate_id']);
            $table->dropIndex(['tenant_id', 'fiscal_tax_mode']);
            $table->dropColumn(['fiscal_tax_mode', 'fiscal_tax_rate_id']);
        });
    }
};
