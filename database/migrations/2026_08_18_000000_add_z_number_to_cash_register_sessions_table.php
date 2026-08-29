<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('z_number')->nullable()->after('status');
            $table->timestamp('z_emitted_at')->nullable()->after('z_number');
            $table->index(['tenant_id', 'cash_register_id', 'z_number']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'cash_register_id', 'z_number']);
            $table->dropColumn(['z_number', 'z_emitted_at']);
        });
    }
};
