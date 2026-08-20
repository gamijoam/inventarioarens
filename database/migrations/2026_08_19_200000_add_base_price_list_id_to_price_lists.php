<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->foreignId('base_price_list_id')->nullable()->after('markup_percentage');
            $table->index(['tenant_id', 'base_price_list_id']);
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'base_price_list_id']);
            $table->dropColumn('base_price_list_id');
        });
    }
};
