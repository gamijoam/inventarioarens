<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('seller_id')->nullable()->after('sale_id')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'seller_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'seller_id']);
            $table->dropConstrainedForeignId('seller_id');
        });
    }
};
