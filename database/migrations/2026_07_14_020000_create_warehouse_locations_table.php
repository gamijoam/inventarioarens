<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id');
            $table->foreignId('parent_id')->nullable();
            $table->string('name', 100);
            $table->string('code', 50);
            $table->string('aisle', 20)->nullable();
            $table->string('rack', 20)->nullable();
            $table->string('bin', 20)->nullable();
            $table->string('level', 20)->nullable();
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'warehouse_id', 'code'], 'warehouse_locations_code_unique');
            $table->index(['tenant_id', 'warehouse_id', 'is_active']);
            $table->index(['tenant_id', 'parent_id']);
            $table->foreign(['tenant_id', 'warehouse_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouses')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parent_id'])
                ->references(['tenant_id', 'id'])
                ->on('warehouse_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_locations');
    }
};
