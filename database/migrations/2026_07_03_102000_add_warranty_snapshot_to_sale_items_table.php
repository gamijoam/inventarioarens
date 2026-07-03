<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('warranty_policy_id')->nullable()->after('stock_movement_id');
            $table->string('warranty_policy_name')->nullable()->after('warranty_policy_id');
            $table->unsignedInteger('warranty_duration_days')->nullable()->after('warranty_policy_name');
            $table->string('warranty_coverage_type')->nullable()->after('warranty_duration_days');
            $table->text('warranty_conditions')->nullable()->after('warranty_coverage_type');
            $table->timestamp('warranty_starts_at')->nullable()->after('warranty_conditions');
            $table->timestamp('warranty_expires_at')->nullable()->after('warranty_starts_at');
            $table->index(['tenant_id', 'warranty_policy_id']);
            $table->index(['tenant_id', 'warranty_expires_at']);
            $table->foreign(['tenant_id', 'warranty_policy_id'])
                ->references(['tenant_id', 'id'])
                ->on('warranty_policies');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'warranty_policy_id']);
            $table->dropIndex(['tenant_id', 'warranty_policy_id']);
            $table->dropIndex(['tenant_id', 'warranty_expires_at']);
            $table->dropColumn([
                'warranty_policy_id',
                'warranty_policy_name',
                'warranty_duration_days',
                'warranty_coverage_type',
                'warranty_conditions',
                'warranty_starts_at',
                'warranty_expires_at',
            ]);
        });
    }
};
