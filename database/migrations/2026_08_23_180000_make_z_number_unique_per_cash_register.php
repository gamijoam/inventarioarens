<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'cash_register_id', 'z_number'],
                'cash_sessions_tenant_register_z_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->dropUnique('cash_sessions_tenant_register_z_unique');
        });
    }
};
