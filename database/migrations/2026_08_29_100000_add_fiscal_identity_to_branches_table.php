<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('fiscal_address', 500)->nullable()->after('status');
            $table->string('fiscal_city', 120)->nullable()->after('fiscal_address');
            $table->string('fiscal_state', 120)->nullable()->after('fiscal_city');
            $table->string('fiscal_phone', 40)->nullable()->after('fiscal_state');
            $table->string('fiscal_email', 150)->nullable()->after('fiscal_phone');
            $table->string('tax_condition', 30)->nullable()->after('fiscal_email');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn([
                'fiscal_address',
                'fiscal_city',
                'fiscal_state',
                'fiscal_phone',
                'fiscal_email',
                'tax_condition',
            ]);
        });
    }
};
