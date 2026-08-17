<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_credit_transactions', function (Blueprint $table): void {
            $table->string('operation_key', 191)->nullable()->after('source_id');
            $table->unique(['tenant_id', 'operation_key'], 'customer_credit_transactions_operation_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_credit_transactions', function (Blueprint $table): void {
            $table->dropUnique('customer_credit_transactions_operation_key_unique');
            $table->dropColumn('operation_key');
        });
    }
};
