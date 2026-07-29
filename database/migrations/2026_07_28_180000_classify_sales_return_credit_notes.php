<?php

use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\SalesReturns\Models\SalesReturn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('financial_adjustments')
            ->where('source_type', SalesReturn::class)
            ->update(['account_type' => FinancialAdjustment::ACCOUNT_CUSTOMER_CREDIT]);
    }

    public function down(): void
    {
        DB::table('financial_adjustments')
            ->where('source_type', SalesReturn::class)
            ->update(['account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE]);
    }
};
