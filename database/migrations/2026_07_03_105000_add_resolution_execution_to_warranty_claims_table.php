<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->foreignId('replacement_product_unit_id')->nullable()->after('product_unit_id');
            $table->foreignId('replacement_stock_movement_id')->nullable()->after('resolution_notes');
            $table->foreignId('resolved_by')->nullable()->after('delivered_by');
            $table->timestamp('resolved_at')->nullable()->after('delivered_at');

            $table->index(['tenant_id', 'replacement_product_unit_id']);

            $table->foreign('replacement_product_unit_id')
                ->references('id')
                ->on('product_units');
            $table->foreign(['tenant_id', 'replacement_stock_movement_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_movements');
            $table->foreign('resolved_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->dropForeign(['replacement_product_unit_id']);
            $table->dropForeign(['tenant_id', 'replacement_stock_movement_id']);
            $table->dropForeign(['resolved_by']);
            $table->dropIndex(['tenant_id', 'replacement_product_unit_id']);
            $table->dropColumn([
                'replacement_product_unit_id',
                'replacement_stock_movement_id',
                'resolved_by',
                'resolved_at',
            ]);
        });
    }
};
