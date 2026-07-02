<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('base_price', 18, 4)->nullable()->after('tracking_type');
            $table->string('sale_currency', 3)->default('USD')->after('base_price');
            $table->foreignId('sale_exchange_rate_type_id')->nullable()->after('sale_currency');
            $table->index(['tenant_id', 'sale_currency']);
            $table->foreign(['tenant_id', 'sale_exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'sale_exchange_rate_type_id']);
            $table->dropIndex(['tenant_id', 'sale_currency']);
            $table->dropColumn(['base_price', 'sale_currency', 'sale_exchange_rate_type_id']);
        });
    }
};
