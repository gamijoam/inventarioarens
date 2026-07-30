<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->decimal('expected_cash_usd', 18, 4)->nullable()->after('expected_local_amount');
            $table->decimal('expected_cash_ves', 18, 4)->nullable()->after('expected_cash_usd');
            $table->decimal('counted_cash_usd', 18, 4)->nullable()->after('counted_local_amount');
            $table->decimal('counted_cash_ves', 18, 4)->nullable()->after('counted_cash_usd');
            $table->decimal('difference_cash_usd', 18, 4)->nullable()->after('difference_local_amount');
            $table->decimal('difference_cash_ves', 18, 4)->nullable()->after('difference_cash_usd');
        });

        // Existing sessions keep their historical totals; these fields are only
        // a compatible physical-cash projection for new reconciliations.
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status', 'cash_register_id']);
        });

        DB::table('cash_register_sessions')->update([
            'expected_cash_usd' => DB::raw('opening_base_amount'),
            'expected_cash_ves' => DB::raw('opening_local_amount'),
            'counted_cash_usd' => DB::raw('counted_base_amount'),
            'counted_cash_ves' => DB::raw('counted_local_amount'),
            'difference_cash_usd' => DB::raw('difference_base_amount'),
            'difference_cash_ves' => DB::raw('difference_local_amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status', 'cash_register_id']);
            $table->dropColumn([
                'expected_cash_usd',
                'expected_cash_ves',
                'counted_cash_usd',
                'counted_cash_ves',
                'difference_cash_usd',
                'difference_cash_ves',
            ]);
        });
    }
};
