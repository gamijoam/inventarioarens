<?php

namespace App\Console\Commands;

use App\Modules\Products\Models\ProductImage;
use App\Modules\Sync\Services\SyncDownloadService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * images:download - Descarga imagenes faltantes del cloud al local.
 *
 * Caso de uso: despues de que un nodo local recibe un evento product.image.uploaded
 * via sync_inbox, el applier crea la fila con cloud_url pero el archivo binario
 * no esta en synced-images todavia. Este comando los baja en background.
 *
 * Uso:
 *   php artisan images:download                  -- procesa todos los tenants activos
 *   php artisan images:download --tenant=mi-empresa   -- solo un tenant
 *   php artisan images:download --limit=50       -- maximo de imagenes por corrida
 *
 * Scheduling sugerido (en cada nodo local):
 *   every 5 min: cd /opt/inventarioarens-cloud && php artisan images:download --limit=20 >> /var/log/images-download.log 2>&1
 *
 * Output:
 *   - downloaded: N (archivos nuevos bajados OK)
 *   - skipped:   N (archivos ya estaban al dia, sha256 match)
 *   - failed:    N (errores de red, se reintentan en la proxima corrida)
 */
class ImagesDownloadCommand extends Command
{
    protected $signature = 'images:download
        {--tenant= : Slug del tenant a procesar (default: todos los activos)}
        {--limit=100 : Maximo de imagenes a procesar por corrida}';

    protected $description = 'Descarga imagenes de productos faltantes del cloud al local (disk synced-images).';

    public function handle(SyncDownloadService $downloader, TenantManager $tenants): int
    {
        $tenantSlug = $this->option('tenant');
        $limit = max(1, (int) $this->option('limit'));

        $query = ProductImage::query()
            // Solo imagenes que tienen storage_path apuntando a un archivo
            // (no URLs externas como fallback image_url).
            ->whereNotNull('storage_path')
            ->where('storage_path', 'not like', 'http%')
            // Solo imagenes que NO son soft-deleted (no descargamos basura).
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->limit($limit);

        if ($tenantSlug) {
            $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
            if (! $tenant) {
                $this->error("Tenant no encontrado: {$tenantSlug}");

                return self::FAILURE;
            }
            $query->where('tenant_id', $tenant->id);
            $tenants->set($tenant);
        }

        $images = $query->get();
        if ($images->isEmpty()) {
            $this->info('No hay imagenes para procesar.');

            return self::SUCCESS;
        }

        $this->info("Procesando {$images->count()} imagen(es)...");

        $stats = ['downloaded' => 0, 'skipped' => 0, 'failed' => 0];
        $bar = $this->output->createProgressBar($images->count());
        $bar->start();
        foreach ($images as $image) {
            // Si el archivo ya esta al dia (sha256 match), no gastamos bandwidth.
            if ($this->isAlreadyUpToDate($image)) {
                $stats['skipped']++;
                $bar->advance();

                continue;
            }

            $ok = $downloader->downloadImage($image);
            if ($ok) {
                $stats['downloaded']++;
            } else {
                $stats['failed']++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info("Descargadas: {$stats['downloaded']} | Saltadas (al dia): {$stats['skipped']} | Fallidas: {$stats['failed']}");

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Check rapido: existe el archivo local y coincide el sha256.
     */
    private function isAlreadyUpToDate(ProductImage $image): bool
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('synced-images');
        $path = $image->storage_path;
        if (! $path || str_starts_with($path, 'http')) {
            return true; // no hay que descargar
        }
        if (! $disk->exists($path)) {
            return false;
        }
        if (! $image->sha256) {
            return true; // sin hash, asumimos OK
        }
        $actual = @hash_file('sha256', $disk->path($path));
        if ($actual === false) {
            return false;
        }

        return hash_equals((string) $image->sha256, (string) $actual);
    }
}
