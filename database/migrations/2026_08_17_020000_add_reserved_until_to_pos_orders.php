<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->timestamp('reserved_until')->nullable()->after('status');
            $table->index(['tenant_id', 'status', 'reserved_until'], 'pos_orders_reservation_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex('pos_orders_reservation_expiry_index');
            $table->dropColumn('reserved_until');
        });
    }
};
