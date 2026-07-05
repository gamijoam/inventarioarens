<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_inbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_uuid');
            $table->foreignId('origin_node_id')->nullable();
            $table->string('event_type');
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->string('payload_hash', 128)->nullable();
            $table->json('payload');
            $table->string('status', 24)->default('received');
            $table->timestamp('received_at');
            $table->timestamp('applied_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'event_uuid']);
            $table->index(['tenant_id', 'status', 'received_at']);
            $table->index(['tenant_id', 'event_type']);
            $table->foreign(['tenant_id', 'origin_node_id'])
                ->references(['tenant_id', 'id'])
                ->on('sync_nodes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_inbox');
    }
};

