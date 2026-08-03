<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->foreignId('payment_exchange_rate_type_id')->nullable()->after('markup_percentage');
            $table->foreign(['tenant_id', 'payment_exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'payment_exchange_rate_type_id']);
            $table->dropColumn('payment_exchange_rate_type_id');
        });
    }
};
