<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_id')->nullable()->after('plan');
            $table->foreign('parent_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
            $table->index(['parent_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false)->after('password');
            $table->index('is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_platform_admin']);
            $table->dropColumn('is_platform_admin');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id', 'status']);
            $table->dropColumn('parent_id');
        });
    }
};