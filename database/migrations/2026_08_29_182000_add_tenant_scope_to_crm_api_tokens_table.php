<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_api_tokens', function (Blueprint $table): void {
            $table->string('tenant_scope', 20)->default('tenant')->after('tenant_id');
            $table->index(['tenant_id', 'tenant_scope']);
        });
    }

    public function down(): void
    {
        Schema::table('crm_api_tokens', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'tenant_scope']);
            $table->dropColumn('tenant_scope');
        });
    }
};
