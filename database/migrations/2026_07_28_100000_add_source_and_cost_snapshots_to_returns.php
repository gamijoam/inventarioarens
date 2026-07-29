<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_adjustments', function (Blueprint $table): void {
            $table->string('source_type')->nullable()->after('accounts_payable_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('base_unit_cost', 18, 4)->nullable()->after('base_total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('financial_adjustments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('base_unit_cost');
        });
    }
};
