<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('fiscal_tax_rate_id')->nullable()->after('warranty_policy_id');
            $table->index(['tenant_id', 'fiscal_tax_rate_id']);
            $table->foreign(['tenant_id', 'fiscal_tax_rate_id'])
                ->references(['tenant_id', 'id'])
                ->on('fiscal_tax_rates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'fiscal_tax_rate_id']);
            $table->dropIndex(['tenant_id', 'fiscal_tax_rate_id']);
            $table->dropColumn('fiscal_tax_rate_id');
        });
    }
};
