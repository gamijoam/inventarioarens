<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'sales_returns_sync_source_unique');
        });

        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->string('sync_source_node_code')->nullable()->after('id');
            $table->unsignedBigInteger('sync_source_id')->nullable()->after('sync_source_node_code');
            $table->unique(['tenant_id', 'sync_source_node_code', 'sync_source_id'], 'sales_return_items_sync_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->dropUnique('sales_return_items_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });

        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropUnique('sales_returns_sync_source_unique');
            $table->dropColumn(['sync_source_node_code', 'sync_source_id']);
        });
    }
};
