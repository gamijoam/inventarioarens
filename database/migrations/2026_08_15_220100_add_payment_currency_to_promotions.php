<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->string('payment_currency', 3)->default('ANY')->after('price_currency');
            $table->index(['tenant_id', 'payment_currency']);
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'payment_currency']);
            $table->dropColumn('payment_currency');
        });
    }
};
