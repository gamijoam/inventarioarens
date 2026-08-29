<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('fiscal_name')->nullable()->after('name');
        });

        DB::table('customers')
            ->whereNull('fiscal_name')
            ->update(['fiscal_name' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('fiscal_name');
        });
    }
};
