<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('cash_register_session_id')->nullable()->after('sale_id');
            $table->index(['tenant_id', 'cash_register_session_id']);
            $table->foreign(['tenant_id', 'cash_register_session_id'])
                ->references(['tenant_id', 'id'])
                ->on('cash_register_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'cash_register_session_id']);
            $table->dropIndex(['tenant_id', 'cash_register_session_id']);
            $table->dropColumn('cash_register_session_id');
        });
    }
};
