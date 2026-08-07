<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_items', function (Blueprint $table): void {
            $table->string('item_role')->default('eligible')->after('quantity');
            $table->dropUnique('promotion_items_tenant_id_promotion_id_product_id_unique');
            $table->unique(
                ['tenant_id', 'promotion_id', 'item_role', 'product_id'],
                'promotion_items_tenant_promotion_role_product_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('promotion_items', function (Blueprint $table): void {
            $table->dropUnique('promotion_items_tenant_promotion_role_product_unique');
            $table->unique(['tenant_id', 'promotion_id', 'product_id']);
            $table->dropColumn('item_role');
        });
    }
};
