<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id');
            $table->string('direction', 16);
            $table->unsignedBigInteger('last_event_id')->nullable();
            $table->uuid('last_event_uuid')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'node_id', 'direction']);
            $table->index(['tenant_id', 'direction']);
            $table->foreign(['tenant_id', 'node_id'])
                ->references(['tenant_id', 'id'])
                ->on('sync_nodes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');
    }
};

