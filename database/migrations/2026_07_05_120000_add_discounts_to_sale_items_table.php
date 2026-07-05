<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('discount_type')->nullable()->after('product_unit_ids');
            $table->decimal('discount_value', 18, 4)->default(0)->after('discount_type');
            $table->decimal('discount_amount', 18, 4)->default(0)->after('discount_value');
            $table->decimal('discount_base_amount', 18, 4)->default(0)->after('discount_amount');
            $table->decimal('discount_local_amount', 18, 4)->default(0)->after('discount_base_amount');
            $table->string('discount_reason')->nullable()->after('discount_local_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn([
                'discount_type',
                'discount_value',
                'discount_amount',
                'discount_base_amount',
                'discount_local_amount',
                'discount_reason',
            ]);
        });
    }
};
