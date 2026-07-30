<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_receivable_payments', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'accounts_receivable_payments_sync_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable_payments', function (Blueprint $table): void {
            $table->dropUnique('accounts_receivable_payments_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });
    }
};
