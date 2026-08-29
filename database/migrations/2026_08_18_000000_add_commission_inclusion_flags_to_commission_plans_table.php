<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_plans', function (Blueprint $table): void {
            $table->boolean('include_combos')->default(true)->after('allow_self_stacking');
            $table->boolean('include_discounts')->default(true)->after('include_combos');
        });
    }

    public function down(): void
    {
        Schema::table('commission_plans', function (Blueprint $table): void {
            $table->dropColumn(['include_combos', 'include_discounts']);
        });
    }
};
