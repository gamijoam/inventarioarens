<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->string('cloud_storage_path', 500)->nullable()->after('storage_path');
        });

        Schema::table('product_image_variants', function (Blueprint $table): void {
            $table->string('cloud_storage_path', 500)->nullable()->after('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropColumn('cloud_storage_path');
        });

        Schema::table('product_image_variants', function (Blueprint $table): void {
            $table->dropColumn('cloud_storage_path');
        });
    }
};
