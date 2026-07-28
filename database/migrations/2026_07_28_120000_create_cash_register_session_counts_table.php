<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_session_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_session_id');
            $table->string('currency', 3);
            $table->decimal('denomination', 18, 4);
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'cash_register_session_id', 'currency', 'denomination'], 'cash_session_counts_denomination_unique');
            $table->foreign(['tenant_id', 'cash_register_session_id'])
                ->references(['tenant_id', 'id'])
                ->on('cash_register_sessions')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'cash_register_session_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_session_counts');
    }
};
