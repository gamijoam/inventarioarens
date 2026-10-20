<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->decimal('payment_exchange_rate_markup_percentage', 8, 4)
                ->nullable()
                ->after('payment_exchange_rate_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropColumn('payment_exchange_rate_markup_percentage');
        });
    }
};
