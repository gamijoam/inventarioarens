<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('service_order_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'service_order_id']);

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'service_order_id'])
                ->references(['tenant_id', 'id'])
                ->on('service_orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_status_history');
    }
};
