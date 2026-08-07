<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_pairing_codes', function (Blueprint $table): void {
            $table->boolean('is_group_bundle')->default(false)->after('target_user_id');
            $table->index(['target_tenant_id', 'is_group_bundle', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_pairing_codes', function (Blueprint $table): void {
            $table->dropIndex(['target_tenant_id', 'is_group_bundle', 'expires_at']);
            $table->dropColumn('is_group_bundle');
        });
    }
};
