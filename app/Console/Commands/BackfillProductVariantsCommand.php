<?php

namespace App\Console\Commands;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea una variante default (color=NULL) por cada producto existente
 * que aun no tenga variantes. Es idempotente: corre varias veces sin duplicar.
 *
 * Itera por tenant y desactiva el scope global de BelongsToTenant para que
 * el comando funcione desde CLI sin un tenant resuelto (es una operacion
 * administrativa de un solo uso por despliegue).
 */
class BackfillProductVariantsCommand extends Command
{
    protected $signature = 'products:backfill-variants {--chunk=200}';

    protected $description = 'Crea una variante default por producto que aun no tenga variantes.';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $created = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($chunk, &$created) {
            Product::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereDoesntHave('variants')
                ->orderBy('id')
                ->chunkById($chunk, function ($products) use ($tenant, &$created) {
                    foreach ($products as $product) {
                        DB::transaction(function () use ($tenant, $product, &$created) {
                            $variant = new ProductVariant;
                            $variant->setRawAttributes([
                                'tenant_id' => $tenant->id,
                                'product_id' => $product->id,
                                'color' => null,
                                'color_hex' => null,
                                'sku_variant' => null,
                                'barcode_variant' => null,
                                'price_override' => null,
                                'is_active' => 1,
                                'position' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $variant->saveQuietly();
                            $created++;
                        });
                    }
                });
        });

        $this->info("Variantes default creadas: {$created}");

        return self::SUCCESS;
    }
}
