<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_requests', function (Blueprint $table): void {
            $table->boolean('logistics_mode')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_requests', function (Blueprint $table): void {
            $table->dropColumn('logistics_mode');
        });
    }
};
