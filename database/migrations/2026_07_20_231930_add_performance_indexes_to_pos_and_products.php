<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Indice compuesto para el listado de ordenes POS (banda de pendientes).
        // Cubre el WHERE status + ORDER BY opened_at DESC + LIMIT sin sort en memoria.
        // Si ya existe uno similar (de una migracion previa), lo respeta.
        DB::statement('CREATE INDEX IF NOT EXISTS pos_orders_tenant_status_opened_idx ON pos_orders (tenant_id, status, opened_at DESC, id DESC)');

        // Indice compuesto para product_units: el scanner y el lookupeador
        // de IMEIs filtran por (product_id, warehouse_id, status). Postgres
        // puede usar uno de los indices existentes y filtrar el resto, pero
        // un indice dedicado evita el residual filter.
        DB::statement('CREATE INDEX IF NOT EXISTS product_units_tenant_product_wh_status_idx ON product_units (tenant_id, product_id, warehouse_id, status)');

        // Indice compuesto para la busqueda de stock por warehouse.
        DB::statement('CREATE INDEX IF NOT EXISTS stock_balances_tenant_wh_product_idx ON stock_balances (tenant_id, warehouse_id, product_id)');

        // Indices trigrama para busqueda fuzzy de productos (POS, inventory).
        // Aceleran LIKE '%term%' sobre name/sku/barcode. Si pg_trgm no esta
        // instalado en el servidor, los omitimos sin abortar la migracion.
        if ($this->ensurePgTrgmExtension()) {
            DB::statement('CREATE INDEX IF NOT EXISTS products_name_trgm_idx ON products USING gin (LOWER(name) gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS products_sku_trgm_idx ON products USING gin (LOWER(sku) gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS products_barcode_trgm_idx ON products USING gin (LOWER(barcode) gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_barcode_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS products_sku_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS products_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS stock_balances_tenant_wh_product_idx');
        DB::statement('DROP INDEX IF EXISTS product_units_tenant_product_wh_status_idx');
        DB::statement('DROP INDEX IF EXISTS pos_orders_tenant_status_opened_idx');

        // No dropeamos la extension pg_trgm en down() porque puede ser usada
        // por otras migraciones externas al modulo POS.
    }

    /**
     * Intenta habilitar la extension pg_trgm. Devuelve true si esta disponible
     * (o se habilito correctamente), false en caso contrario.
     */
    private function ensurePgTrgmExtension(): bool
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
};
