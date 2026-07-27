<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('node_name', 120);
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->string('redeemed_node_code', 120)->nullable();
            $table->timestamps();

            $table->index(['target_tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_pairing_codes');
    }
};
