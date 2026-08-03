<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intercompany_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('inventory_transfer_request_id')->constrained('inventory_transfer_requests')->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->string('title');
            $table->text('message');
            $table->string('action_url');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'inventory_transfer_request_id', 'event_type'],
                'intercompany_notifications_event_unique'
            );
            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('intercompany_notification_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('notification_id')->constrained('intercompany_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['notification_id', 'user_id']);
            $table->index(['tenant_id', 'user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intercompany_notification_reads');
        Schema::dropIfExists('intercompany_notifications');
    }
};
