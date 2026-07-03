<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('warranty_policy_id')->nullable()->after('sale_exchange_rate_type_id');
            $table->index(['tenant_id', 'warranty_policy_id']);
            $table->foreign(['tenant_id', 'warranty_policy_id'])
                ->references(['tenant_id', 'id'])
                ->on('warranty_policies');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'warranty_policy_id']);
            $table->dropIndex(['tenant_id', 'warranty_policy_id']);
            $table->dropColumn('warranty_policy_id');
        });
    }
};
