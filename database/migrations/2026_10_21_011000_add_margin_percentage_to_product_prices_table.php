<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_prices', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_prices', 'margin_percentage')) {
                $table->decimal('margin_percentage', 8, 2)->nullable()->after('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_prices', function (Blueprint $table): void {
            if (Schema::hasColumn('product_prices', 'margin_percentage')) {
                $table->dropColumn('margin_percentage');
            }
        });
    }
};
