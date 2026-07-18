<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('rejection_reason')->nullable()->after('reviewed_at');
            $table->foreignId('processed_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->after('processed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');
            $table->string('refund_currency', 3)->nullable()->after('cancellation_reason');
            $table->decimal('refund_amount', 18, 4)->nullable()->after('refund_currency');
            $table->foreignId('refund_exchange_rate_type_id')->nullable()->after('refund_amount');
            $table->string('refund_exchange_rate_type_code')->nullable()->after('refund_exchange_rate_type_id');
            $table->decimal('refund_exchange_rate', 18, 6)->nullable()->after('refund_exchange_rate_type_code');
            $table->decimal('refund_amount_base', 18, 4)->nullable()->after('refund_exchange_rate');
            $table->decimal('refund_amount_local', 18, 4)->nullable()->after('refund_amount_base');
            $table->string('refund_method')->nullable()->after('refund_amount_local');
            $table->string('refund_reference')->nullable()->after('refund_method');
            $table->foreignId('refund_cash_register_movement_id')->nullable()->after('refund_reference');
            $table->foreignId('refund_financial_adjustment_id')->nullable()->after('refund_cash_register_movement_id');
            $table->text('process_notes')->nullable()->after('refund_financial_adjustment_id');

            $table->foreign(['tenant_id', 'refund_exchange_rate_type_id'])
                ->references(['tenant_id', 'id'])
                ->on('exchange_rate_types');
            $table->foreign(['tenant_id', 'refund_cash_register_movement_id'])
                ->references(['tenant_id', 'id'])
                ->on('cash_register_movements');
            $table->foreign(['tenant_id', 'refund_financial_adjustment_id'])
                ->references(['tenant_id', 'id'])
                ->on('financial_adjustments');
        });

        DB::table('sales_returns')
            ->where('status', 'processed')
            ->whereNull('processed_by')
            ->update(['processed_by' => DB::raw('created_by')]);
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'refund_financial_adjustment_id']);
            $table->dropForeign(['tenant_id', 'refund_cash_register_movement_id']);
            $table->dropForeign(['tenant_id', 'refund_exchange_rate_type_id']);

            $table->dropColumn([
                'reviewed_by',
                'reviewed_at',
                'rejection_reason',
                'processed_by',
                'cancelled_by',
                'cancelled_at',
                'cancellation_reason',
                'refund_currency',
                'refund_amount',
                'refund_exchange_rate_type_id',
                'refund_exchange_rate_type_code',
                'refund_exchange_rate',
                'refund_amount_base',
                'refund_amount_local',
                'refund_method',
                'refund_reference',
                'refund_cash_register_movement_id',
                'refund_financial_adjustment_id',
                'process_notes',
            ]);
        });
    }
};
