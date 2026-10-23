<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('payment_price_list_id')->nullable()->after('customer_id');
            $table->boolean('payment_price_list_explicit')->default(false)->after('payment_price_list_id');
            $table->foreign(['tenant_id', 'payment_price_list_id'])
                ->references(['tenant_id', 'id'])
                ->on('price_lists')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'payment_price_list_id']);
            $table->dropColumn(['payment_price_list_id', 'payment_price_list_explicit']);
        });
    }
};
