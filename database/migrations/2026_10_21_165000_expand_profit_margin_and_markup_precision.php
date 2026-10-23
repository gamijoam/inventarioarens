<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ALTER COLUMN profit_margin TYPE NUMERIC(12, 2);');
            DB::statement('ALTER TABLE price_lists ALTER COLUMN markup_percentage TYPE NUMERIC(12, 2);');
            if (Schema::hasColumn('product_prices', 'margin_percentage')) {
                DB::statement('ALTER TABLE product_prices ALTER COLUMN margin_percentage TYPE NUMERIC(12, 2);');
            }
        } else {
            Schema::table('products', function (Blueprint $table): void {
                $table->decimal('profit_margin', 12, 2)->nullable()->change();
            });
            Schema::table('price_lists', function (Blueprint $table): void {
                $table->decimal('markup_percentage', 12, 2)->nullable()->change();
            });
            if (Schema::hasColumn('product_prices', 'margin_percentage')) {
                Schema::table('product_prices', function (Blueprint $table): void {
                    $table->decimal('margin_percentage', 12, 2)->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ALTER COLUMN profit_margin TYPE NUMERIC(5, 2);');
            DB::statement('ALTER TABLE price_lists ALTER COLUMN markup_percentage TYPE NUMERIC(5, 2);');
        } else {
            Schema::table('products', function (Blueprint $table): void {
                $table->decimal('profit_margin', 5, 2)->nullable()->change();
            });
            Schema::table('price_lists', function (Blueprint $table): void {
                $table->decimal('markup_percentage', 5, 2)->nullable()->change();
            });
        }
    }
};
