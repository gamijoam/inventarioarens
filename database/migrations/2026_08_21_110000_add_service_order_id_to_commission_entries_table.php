<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_entries', function (Blueprint $table) {
            $table->foreignId('service_order_id')->nullable()->after('sales_return_id');
            $table->index(['tenant_id', 'service_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_entries', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'service_order_id']);
            $table->dropColumn('service_order_id');
        });
    }
};
