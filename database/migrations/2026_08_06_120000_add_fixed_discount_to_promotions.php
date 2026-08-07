<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->decimal('discount_amount_usd', 18, 4)->nullable()->after('discount_percent');
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('promotion_discount_amount_usd', 18, 4)->nullable()->after('promotion_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('promotion_discount_amount_usd');
        });

        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropColumn('discount_amount_usd');
        });
    }
};
