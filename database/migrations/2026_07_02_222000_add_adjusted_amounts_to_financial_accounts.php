<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_receivables', function (Blueprint $table): void {
            $table->decimal('adjusted_base_amount', 18, 4)->default(0)->after('collected_local_amount');
            $table->decimal('adjusted_local_amount', 18, 4)->default(0)->after('adjusted_base_amount');
        });

        Schema::table('accounts_payables', function (Blueprint $table): void {
            $table->decimal('adjusted_base_amount', 18, 4)->default(0)->after('paid_local_amount');
            $table->decimal('adjusted_local_amount', 18, 4)->default(0)->after('adjusted_base_amount');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivables', function (Blueprint $table): void {
            $table->dropColumn(['adjusted_base_amount', 'adjusted_local_amount']);
        });

        Schema::table('accounts_payables', function (Blueprint $table): void {
            $table->dropColumn(['adjusted_base_amount', 'adjusted_local_amount']);
        });
    }
};
