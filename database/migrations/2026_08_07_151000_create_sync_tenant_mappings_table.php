<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_tenant_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('local_tenant_id')->constrained('tenants')->cascadeOnDelete()->unique();
            $table->unsignedBigInteger('remote_tenant_id')->unique();
            $table->unsignedBigInteger('remote_parent_id')->nullable()->index();
            $table->string('remote_slug', 120);
            $table->boolean('is_group')->default(false);
            $table->timestamps();

            $table->index(['remote_parent_id', 'is_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_tenant_mappings');
    }
};
