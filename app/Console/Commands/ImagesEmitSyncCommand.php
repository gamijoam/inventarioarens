<?php

namespace App\Console\Commands;

use App\Modules\Products\Models\ProductImage;
use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;

class ImagesEmitSyncCommand extends Command
{
    protected $signature = 'images:emit-sync
        {--tenant= : Slug del tenant a procesar}
        {--product-id= : ID interno del producto}
        {--product-sku= : SKU exacto del producto}
        {--limit=500 : Maximo de imagenes por tenant}
        {--dry-run : Mostrar lo que se emitiria sin crear outbox}';

    protected $description = 'Reemite eventos sync product.image.uploaded para imagenes ya guardadas.';

    public function handle(TenantManager $tenants, SyncCatalogOutboxService $outbox): int
    {
        $tenantSlug = $this->option('tenant');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $tenantQuery = Tenant::query()->orderBy('id');
        if ($tenantSlug) {
            $tenantQuery->where('slug', $tenantSlug);
        }

        $tenantList = $tenantQuery->get();
        if ($tenantList->isEmpty()) {
            $this->error($tenantSlug ? "Tenant no encontrado: {$tenantSlug}" : 'No hay tenants para procesar.');

            return self::FAILURE;
        }

        $total = 0;
        foreach ($tenantList as $tenant) {
            $tenants->set($tenant);
            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId($tenant->id);
            }

            $query = ProductImage::query()
                ->with(['variants', 'product'])
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->limit($limit);

            if ($this->option('product-id')) {
                $query->where('product_id', (int) $this->option('product-id'));
            }

            if ($this->option('product-sku')) {
                $sku = (string) $this->option('product-sku');
                $query->whereHas('product', fn ($productQuery) => $productQuery->where('sku', $sku));
            }

            $images = $query->get();
            if ($images->isEmpty()) {
                $this->line("{$tenant->slug}: sin imagenes para emitir.");

                continue;
            }

            foreach ($images as $image) {
                if ($dryRun) {
                    $this->line("DRY {$tenant->slug}: product {$image->product_id}, image {$image->id}, uuid {$image->uuid}");
                } else {
                    $outbox->imageUploaded($image);
                }
                $total++;
            }

            $action = $dryRun ? 'detectadas' : 'emitidas';
            $this->info("{$tenant->slug}: {$images->count()} imagen(es) {$action}.");
        }

        $this->info("Total: {$total}");

        return self::SUCCESS;
    }
}
