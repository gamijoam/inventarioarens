<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->decimal('discount_percent', 5, 2)->nullable()->after('price_usd');
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('promotion_discount_percent', 5, 2)->nullable()->after('promotion_price_usd');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('promotion_discount_percent');
        });

        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropColumn('discount_percent');
        });
    }
};
