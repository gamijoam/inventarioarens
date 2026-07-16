<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega profit_margin a la tabla products.
 *
 * Reglas:
 * - decimal(5,2): permite hasta 999.99% (cubre cualquier caso real).
 * - nullable: si es null, no se recalcula base_price al recibir compras.
 * - default 25.00: el admin puede dejarlo vacio o cambiarlo por producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('profit_margin', 5, 2)
                ->nullable()
                ->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('profit_margin');
        });
    }
};
