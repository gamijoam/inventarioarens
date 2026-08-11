<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_manual_movements', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id');
            $table->index(['tenant_id', 'product_variant_id'], 'inv_manual_movements_tenant_variant_index');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_manual_movements', function (Blueprint $table): void {
            $table->dropIndex('inv_manual_movements_tenant_variant_index');
            $table->dropColumn('product_variant_id');
        });
    }
};
