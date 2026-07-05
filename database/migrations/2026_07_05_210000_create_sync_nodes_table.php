<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type', 24)->default('local');
            $table->string('status', 24)->default('active');
            $table->foreignId('branch_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type', 'status']);
            $table->foreign(['tenant_id', 'branch_id'])
                ->references(['tenant_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_nodes');
    }
};

