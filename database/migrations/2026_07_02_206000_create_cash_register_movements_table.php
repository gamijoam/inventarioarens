<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_session_id');
            $table->string('type');
            $table->string('method')->nullable();
            $table->string('currency', 3);
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4);
            $table->decimal('amount_local', 18, 4)->nullable();
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('exchange_rate_type_code')->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'cash_register_session_id']);
            $table->index(['tenant_id', 'type']);
            $table->foreign(['tenant_id', 'cash_register_session_id'])
                ->references(['tenant_id', 'id'])
                ->on('cash_register_sessions')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_movements');
    }
};
