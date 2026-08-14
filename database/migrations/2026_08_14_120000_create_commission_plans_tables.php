<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('beneficiary_role');
            $table->decimal('percentage', 7, 4);
            $table->string('conversion_policy')->default('sale_snapshot');
            $table->foreignId('exchange_rate_type_id')->nullable();
            $table->string('credit_policy')->default('proportional_collections');
            $table->unsignedInteger('maturation_days')->default(0);
            $table->boolean('allow_self_stacking')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'beneficiary_role', 'is_active']);
            $table->foreign(['tenant_id', 'exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types')
                ->nullOnDelete();
        });

        Schema::create('commission_plan_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_plan_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'commission_plan_id', 'user_id']);
            $table->index(['tenant_id', 'user_id', 'is_active']);
            $table->foreign(['tenant_id', 'commission_plan_id'])
                ->references(['tenant_id', 'id'])
                ->on('commission_plans')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_plan_assignments');
        Schema::dropIfExists('commission_plans');
    }
};
