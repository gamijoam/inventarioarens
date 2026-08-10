<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'product_variants_tenant_id_id_unique');
        });

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
            $table->index(['tenant_id', 'product_variant_id']);
            $table->foreign(['tenant_id', 'product_variant_id'])
                ->references(['tenant_id', 'id'])
                ->on('product_variants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'product_variant_id']);
            $table->dropIndex(['tenant_id', 'product_variant_id']);
            $table->dropColumn('product_variant_id');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique('product_variants_tenant_id_id_unique');
        });
    }
};
