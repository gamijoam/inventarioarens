<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 50)->nullable()->after('sku');
            $table->text('description')->nullable()->after('name');
            $table->text('long_description')->nullable()->after('description');
            $table->string('unit_of_measure', 20)->default('unit')->after('long_description');
            $table->boolean('track_stock')->default(true)->after('unit_of_measure');
            $table->decimal('min_stock', 18, 4)->nullable()->after('base_price');
            $table->decimal('max_stock', 18, 4)->nullable()->after('min_stock');
            $table->decimal('reorder_quantity', 18, 4)->nullable()->after('max_stock');
            $table->decimal('average_cost', 18, 4)->nullable()->after('reorder_quantity');
            $table->string('image_url', 500)->nullable()->after('average_cost');

            $table->unique(['tenant_id', 'barcode'], 'products_barcode_unique');
            $table->index(['tenant_id', 'min_stock'], 'products_min_stock_idx');
            $table->index(['tenant_id', 'tracking_type', 'is_active'], 'products_tracking_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_barcode_unique');
            $table->dropIndex('products_min_stock_idx');
            $table->dropIndex('products_tracking_active_idx');

            $table->dropColumn([
                'barcode',
                'description',
                'long_description',
                'unit_of_measure',
                'track_stock',
                'min_stock',
                'max_stock',
                'reorder_quantity',
                'average_cost',
                'image_url',
            ]);
        });
    }
};
