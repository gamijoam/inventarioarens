<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_request_guides', function (Blueprint $table): void {
            $table->foreignId('carrier_user_id')->nullable()->after('carrier_company');
            $table->foreign('carrier_user_id', 'request_guide_carrier_user_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_request_guides', function (Blueprint $table): void {
            $table->dropForeign('request_guide_carrier_user_fk');
            $table->dropColumn('carrier_user_id');
        });
    }
};
