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
        }
        // SQLite uses dynamic typing (NUMERIC affinity) and does not enforce fixed DECIMAL precision.
        // Attempting Schema::table()->change() on SQLite drops and recreates tables with __temp__,
        // which triggers syntax errors and breaks foreign keys on established local databases.
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ALTER COLUMN profit_margin TYPE NUMERIC(5, 2);');
            DB::statement('ALTER TABLE price_lists ALTER COLUMN markup_percentage TYPE NUMERIC(5, 2);');
        }
    }
};
