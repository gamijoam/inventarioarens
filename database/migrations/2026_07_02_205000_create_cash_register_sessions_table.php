<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id');
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open');
            $table->decimal('opening_base_amount', 18, 4)->default(0);
            $table->decimal('opening_local_amount', 18, 4)->default(0);
            $table->decimal('expected_base_amount', 18, 4)->default(0);
            $table->decimal('expected_local_amount', 18, 4)->default(0);
            $table->decimal('counted_base_amount', 18, 4)->nullable();
            $table->decimal('counted_local_amount', 18, 4)->nullable();
            $table->decimal('difference_base_amount', 18, 4)->nullable();
            $table->decimal('difference_local_amount', 18, 4)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'cashier_id', 'status']);
            $table->foreign(['tenant_id', 'branch_id'])
                ->references(['tenant_id', 'id'])
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
    }
};
