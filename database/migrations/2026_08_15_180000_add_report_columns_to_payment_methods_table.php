<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->string('report_code', 20)->nullable()->after('code');
            $table->string('report_label', 80)->nullable()->after('report_code');
            $table->boolean('report_visible')->default(true)->after('report_label');
            $table->unsignedInteger('report_sort_order')->default(0)->after('report_visible');
            $table->index(['tenant_id', 'report_visible', 'report_sort_order']);
        });

        DB::table('payment_methods')
            ->whereNull('report_code')
            ->update(['report_code' => DB::raw('UPPER(code)'), 'report_label' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'report_visible', 'report_sort_order']);
            $table->dropColumn(['report_code', 'report_label', 'report_visible', 'report_sort_order']);
        });
    }
};
