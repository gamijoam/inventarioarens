<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bot_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('telegram_chat_id', 64);
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Un telegram_id identifica a una persona en la app; puede tener
            // acceso a varias empresas (Owner), pero el chat es uno solo.
            $table->unique('telegram_chat_id');
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_users');
    }
};
