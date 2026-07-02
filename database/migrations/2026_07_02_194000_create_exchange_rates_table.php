<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exchange_rate_type_id');
            $table->string('base_currency', 3)->default('USD');
            $table->string('quote_currency', 3)->default('VES');
            $table->decimal('rate', 18, 6);
            $table->dateTime('effective_at');
            $table->boolean('is_active')->default(false);
            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'base_currency', 'quote_currency', 'is_active']);
            $table->index(['tenant_id', 'exchange_rate_type_id', 'effective_at']);
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
