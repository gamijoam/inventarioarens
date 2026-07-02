<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('document_type', 1);
            $table->string('document_number');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('fiscal_address')->nullable();
            $table->boolean('is_generic')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'document_type', 'document_number']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'is_generic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
