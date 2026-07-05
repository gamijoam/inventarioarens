<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_uuid');
            $table->foreignId('origin_node_id')->nullable();
            $table->foreignId('target_node_id')->nullable();
            $table->string('target_scope', 32)->default('tenant');
            $table->string('event_type');
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->uuid('aggregate_uuid')->nullable();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('available_at')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'event_uuid']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'available_at']);
            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'aggregate_type', 'aggregate_id']);
            $table->foreign(['tenant_id', 'origin_node_id'])
                ->references(['tenant_id', 'id'])
                ->on('sync_nodes')
                ->nullOnDelete();
            $table->foreign(['tenant_id', 'target_node_id'])
                ->references(['tenant_id', 'id'])
                ->on('sync_nodes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_outbox');
    }
};

