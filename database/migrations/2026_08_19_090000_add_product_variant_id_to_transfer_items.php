<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id');
        });

        Schema::table('inventory_transfer_request_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('origin_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_items', function (Blueprint $table): void {
            $table->dropColumn('product_variant_id');
        });

        Schema::table('inventory_transfer_request_items', function (Blueprint $table): void {
            $table->dropColumn('product_variant_id');
        });
    }
};
