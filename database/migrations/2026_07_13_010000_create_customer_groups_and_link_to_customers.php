<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_group_id')->nullable()->after('zone_id');
            $table->foreign('customer_group_id')
                ->references('id')
                ->on('customer_groups')
                ->nullOnDelete();
            $table->index(['tenant_id', 'customer_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['customer_group_id']);
            $table->dropIndex(['tenant_id', 'customer_group_id']);
            $table->dropColumn('customer_group_id');
        });

        Schema::dropIfExists('customer_groups');
    }
};