<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('pricing_mode', 20)->default('automatic')->after('profit_margin');
        });

        // Existing base prices were entered as final sale prices. Preserve them
        // until each product is explicitly moved to automatic pricing.
        DB::table('products')->update(['pricing_mode' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('pricing_mode');
        });
    }
};
