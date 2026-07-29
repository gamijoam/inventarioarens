<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->unsignedBigInteger('exchange_sale_id')->nullable()->after('customer_credit_transaction_id');
            $table->index(['tenant_id', 'exchange_sale_id']);
            $table->foreign(['tenant_id', 'exchange_sale_id'])
                ->references(['tenant_id', 'id'])
                ->on('sales')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'exchange_sale_id']);
            $table->dropIndex(['tenant_id', 'exchange_sale_id']);
            $table->dropColumn('exchange_sale_id');
        });
    }
};
