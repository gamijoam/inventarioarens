<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_entity_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('remote_tenant_id');
            $table->unsignedBigInteger('remote_id');
            $table->foreignId('local_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('local_id');
            $table->string('remote_key', 160)->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'remote_tenant_id', 'remote_id']);
            $table->index(['local_tenant_id', 'entity_type', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_entity_mappings');
    }
};
