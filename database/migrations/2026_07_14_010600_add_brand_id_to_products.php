<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('tracking_type');

            $table->index(['tenant_id', 'brand_id'], 'products_brand_idx');
            $table->foreign(['tenant_id', 'brand_id'])
                ->references(['tenant_id', 'id'])
                ->on('brands')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'brand_id']);
            $table->dropIndex('products_brand_idx');
            $table->dropColumn('brand_id');
        });
    }
};