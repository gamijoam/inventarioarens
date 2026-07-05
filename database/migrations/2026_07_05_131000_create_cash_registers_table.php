<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id');
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->foreign(['tenant_id', 'branch_id'])
                ->references(['tenant_id', 'id'])
                ->on('branches')
                ->cascadeOnDelete();
        });

        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->foreignId('cash_register_id')->nullable()->after('branch_id');
            $table->foreign(['tenant_id', 'cash_register_id'])
                ->references(['tenant_id', 'id'])
                ->on('cash_registers')
                ->nullOnDelete();
            $table->index(['tenant_id', 'cash_register_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'cash_register_id']);
            $table->dropIndex(['tenant_id', 'cash_register_id', 'status']);
            $table->dropColumn('cash_register_id');
        });

        Schema::dropIfExists('cash_registers');
    }
};
