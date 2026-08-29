<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_inbox', function (Blueprint $table): void {
            $table->timestamp('occurred_at')->nullable()->after('payload');
            $table->index(['tenant_id', 'aggregate_type', 'aggregate_id', 'occurred_at'], 'sync_inbox_aggregate_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sync_inbox', function (Blueprint $table): void {
            $table->dropIndex('sync_inbox_aggregate_occurred_idx');
            $table->dropColumn('occurred_at');
        });
    }
};
