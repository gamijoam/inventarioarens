<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_bootstrap_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('target_node_id')->nullable()->index();
            $table->string('installation_code', 80);
            $table->string('snapshot_key', 180);
            $table->string('session_token_hash', 64)->unique();
            $table->unsignedBigInteger('snapshot_cutoff_id')->nullable();
            $table->unsignedInteger('snapshot_event_count')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'installation_code', 'status']);
            $table->index(['tenant_id', 'target_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_bootstrap_sessions');
    }
};
