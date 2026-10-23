<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_manual_movements', function (Blueprint $table) {
            $table->json('serial_units')->nullable()->after('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_manual_movements', function (Blueprint $table) {
            $table->dropColumn('serial_units');
        });
    }
};
