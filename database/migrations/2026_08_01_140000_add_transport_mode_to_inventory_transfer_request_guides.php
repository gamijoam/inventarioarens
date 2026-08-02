<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_request_guides', function (Blueprint $table): void {
            $table->string('transport_mode', 20)->default('simple')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_request_guides', function (Blueprint $table): void {
            $table->dropColumn('transport_mode');
        });
    }
};
