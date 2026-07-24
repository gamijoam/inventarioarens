<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_product_id')->nullable()->after('tenant_id');
            $table->boolean('is_catalog_master')->default(false)->after('catalog_product_id');
            $table->boolean('is_catalog_active')->default(true)->after('is_catalog_master');

            $table->index(['catalog_product_id'], 'products_catalog_product_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX products_tenant_catalog_unique
             ON products (tenant_id, catalog_product_id)
             WHERE catalog_product_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_tenant_catalog_unique');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_catalog_product_idx');
            $table->dropColumn(['catalog_product_id', 'is_catalog_master', 'is_catalog_active']);
        });
    }
};