<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_tenant_readiness', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('installation_code', 80);
            $table->string('node_code', 80)->nullable();
            $table->string('node_name')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamp('last_push_at')->nullable();
            $table->timestamp('last_pull_at')->nullable();
            $table->timestamp('last_apply_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('initial_sync_completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'installation_code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'node_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_tenant_readiness');
    }
};
