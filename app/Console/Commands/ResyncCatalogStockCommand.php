<?php

namespace App\Console\Commands;

use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Resincroniza min_stock / max_stock / reorder_quantity de los productos
 * maestros hacia sus copias en los spinoffs.
 *
 * Util cuando ya hay catalogos creados antes de que `min_stock`,
 * `max_stock` y `reorder_quantity` se incluyeran en `MASTER_FIELDS`
 * (cambio del 2026-07-23). El comando itera todos los productos
 * maestros con copias en spinoffs y sobreescribe los tres campos en
 * cada copia con los valores actuales del maestro.
 *
 * Uso:
 *   php artisan catalog:resync-stock                 # todos los grupos
 *   php artisan catalog:resync-stock --group=14     # un grupo especifico
 *   php artisan catalog:resync-stock --dry-run     # ver sin modificar
 */
class ResyncCatalogStockCommand extends Command
{
    protected $signature = 'catalog:resync-stock
        {--group= : ID del grupo a resincronizar (opcional, default: todos)}
        {--dry-run : Muestra los cambios sin aplicarlos}';

    protected $description = 'Sincroniza min_stock/max_stock/reorder_quantity del producto maestro a sus copias en spinoffs.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $groupId = $this->option('group');

        $query = Product::query()
            ->withoutGlobalScopes()
            ->where('is_catalog_master', true);

        if ($groupId !== null) {
            $query->where('tenant_id', (int) $groupId);
        }

        $masters = $query->get();

        $this->info(sprintf(
            'Procesando %d producto(s) maestro(s) %s...',
            $masters->count(),
            $dryRun ? '(dry-run)' : '',
        ));

        $totalCopies = 0;
        $totalUpdates = 0;

        foreach ($masters as $master) {
            $spinoffs = Tenant::query()
                ->where('parent_id', $master->tenant_id)
                ->where('is_group', false)
                ->get();

            foreach ($spinoffs as $spinoff) {
                $copy = Product::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $spinoff->id)
                    ->where('catalog_product_id', $master->id)
                    ->first();

                if (! $copy) {
                    continue;
                }

                $totalCopies++;
                $updates = [
                    'min_stock' => $master->min_stock,
                    'max_stock' => $master->max_stock,
                    'reorder_quantity' => $master->reorder_quantity,
                ];

                if ($dryRun) {
                    $this->line(sprintf(
                        '  [dry-run] copia %d (spinoff %d, sku=%s): min=%s max=%s reorder=%s',
                        $copy->id,
                        $spinoff->id,
                        $master->sku,
                        $updates['min_stock'] ?? 'null',
                        $updates['max_stock'] ?? 'null',
                        $updates['reorder_quantity'] ?? 'null',
                    ));

                    continue;
                }

                $copy->fill($updates)->save();
                $totalUpdates++;
            }
        }

        $this->info(sprintf(
            'Listo. Copias procesadas: %d, actualizaciones aplicadas: %d.',
            $totalCopies,
            $totalUpdates,
        ));

        return self::SUCCESS;
    }
}
