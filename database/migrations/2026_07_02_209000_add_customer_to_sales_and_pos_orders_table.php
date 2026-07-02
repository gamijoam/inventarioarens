<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('status');
            $table->index(['tenant_id', 'customer_id']);
            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on('customers');
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('cash_register_session_id');
            $table->index(['tenant_id', 'customer_id']);
            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on('customers');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'customer_id']);
            $table->dropIndex(['tenant_id', 'customer_id']);
            $table->dropColumn('customer_id');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'customer_id']);
            $table->dropIndex(['tenant_id', 'customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
