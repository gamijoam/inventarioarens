<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->timestamp('reservation_expires_at')->nullable()->after('reference_id');
            $table->index(['tenant_id', 'type', 'reservation_expires_at'], 'stock_movements_reservation_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_reservation_expiry_idx');
            $table->dropColumn('reservation_expires_at');
        });
    }
};
