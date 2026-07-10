<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('received_at');
            $table->foreignId('cancelled_by')->nullable()->after('received_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
        });
    }
};
