<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_profiles', function (Blueprint $table): void {
            // Mostrar el monto en $ de cada item (si se desactiva, se puede
            // mostrar en su lugar la lista de precio).
            $table->boolean('show_item_price')->default(true);
            // Mostrar la lista de precio con la que se facturo cada item.
            $table->boolean('show_item_price_list')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('print_profiles', function (Blueprint $table): void {
            $table->dropColumn(['show_item_price', 'show_item_price_list']);
        });
    }
};
