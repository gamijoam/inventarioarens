<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            $table->dropUnique('idempotency_keys_key_method_path_unique');
            $table->unique(
                ['tenant_id', 'key', 'method', 'path'],
                'idempotency_keys_tenant_key_method_path_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropUnique('idempotency_keys_tenant_key_method_path_unique');
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->unique(['key', 'method', 'path']);
        });
    }
};
