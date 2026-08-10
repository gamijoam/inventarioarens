<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elimina los UNIQUE parciales de stock_balances que NO contemplan
 * product_variant_id.
 *
 * Historia:
 * - 2026_07_14: se crearon UNIQUE parciales (tenant, warehouse, product)
 *   con/sin location_id para soportar ubicaciones de almacen.
 * - 2026_07_30: se creo UNIQUE (tenant, warehouse, product, product_variant_id)
 *   para soportar variantes/colores.
 *
 * Ambos conviven: el parcial viejo impide recibir dos variantes del mismo
 * producto (verde + azul) en el mismo almacen, porque exige UNA fila por
 * (tenant, warehouse, product) cuando location_id IS NULL -> 500 al recibir.
 *
 * El UNIQUE nuevo por variante (que incluye NULL en location_id como fila
 * unica por variante) sustituye a los parciales. Se dropean los viejos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS stock_balances_unique_no_location');
        DB::statement('DROP INDEX IF EXISTS stock_balances_unique_with_location');
    }

    public function down(): void
    {
        DB::statement('CREATE UNIQUE INDEX stock_balances_unique_no_location ON stock_balances (tenant_id, warehouse_id, product_id) WHERE location_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX stock_balances_unique_with_location ON stock_balances (tenant_id, warehouse_id, location_id, product_id) WHERE location_id IS NOT NULL');
    }
};
