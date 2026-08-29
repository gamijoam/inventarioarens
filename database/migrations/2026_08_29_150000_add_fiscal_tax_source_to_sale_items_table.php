<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('fiscal_tax_source', 32)->default('product')->after('fiscal_tax_code');
            $table->string('fiscal_tax_override_code', 50)->nullable()->after('fiscal_tax_source');
            $table->index(['tenant_id', 'fiscal_tax_source']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'fiscal_tax_source']);
            $table->dropColumn(['fiscal_tax_source', 'fiscal_tax_override_code']);
        });
    }
};
