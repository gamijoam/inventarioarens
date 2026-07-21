<?php

namespace App\Modules\Products\Models;

use App\Models\User;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Imagen propia de un producto (subida al storage del tenant, no URL externa).
 *
 * Diferencia con `products.image_url` (campo string):
 *  - `image_url` es una URL externa (proveedor, fabricante). NO se replica via sync.
 *  - `ProductImage` es una imagen SUBIDA al storage del VPS. SI se replica via sync.
 *
 * Cada imagen tiene 3 variantes generadas server-side al upload (original, medium, thumb),
 * todas WebP. Los `url` accessors sirven cada variante directo desde el storage
 * (sin pasar por PHP/Laravel, gracias al alias `/storage/` en nginx).
 *
 * El sync replica por evento `product.image.{uploaded,updated,deleted}` con el payload
 * completo (cloud_url + sha256 + variants). El local descarga con `SyncDownloadService`
 * (Fase 3 — todavia no implementado en este commit).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $product_id
 * @property int|null $uploaded_by
 * @property string $storage_path
 * @property string $mime
 * @property int $size
 * @property string|null $original_name
 * @property int|null $width
 * @property int|null $height
 * @property string|null $sha256
 * @property string|null $alt
 * @property int $sort
 * @property bool $is_primary
 */
#[Fillable([
    'uuid',
    'product_id',
    'uploaded_by',
    'storage_path',
    'mime',
    'size',
    'original_name',
    'width',
    'height',
    'sha256',
    'alt',
    'sort',
    'is_primary',
])]
class ProductImage extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'product_images';

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    // ---- Relationships ----

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductImageVariant::class, 'product_image_id');
    }

    // ---- URL accessors ----

    /**
     * URL publica (CDN/cloud cuando el local apunta al cloud, o /storage/ nativo).
     * Construye con disk `public` (storage:link ya hecho) o cloud URL segun APP_URL.
     */
    public function url(): string
    {
        $relPath = $this->storage_path;

        return $this->storagePublicUrl($relPath);
    }

    /**
     * URL de la variante medium (800x800 cover, WebP). Si no existe (imagen legacy),
     * cae al original.
     */
    public function mediumUrl(): string
    {
        $variant = $this->variants()->where('variant', 'medium')->first();
        $rel = $variant?->storage_path ?? $this->storage_path;

        return $this->storagePublicUrl($rel);
    }

    /**
     * URL de la variante thumb (200x200 cover, WebP).
     */
    public function thumbUrl(): string
    {
        $variant = $this->variants()->where('variant', 'thumb')->first();
        $rel = $variant?->storage_path ?? $this->storage_path;

        return $this->storagePublicUrl($rel);
    }

    private function storagePublicUrl(string $relPath): string
    {
        // Si el valor es una URL completa (caso comun: storage_path = cloud_url
        // cuando el local todavia no descargo el archivo), devolver tal cual.
        if (preg_match('#^https?://#', $relPath) === 1) {
            return $relPath;
        }

        // Si la imagen vive en un disk CDN/S3 remoto, delega al driver.
        // En el VPS, el disk `public` se sirve via /storage/* (symlink + nginx alias).
        $disk = config('filesystems.disks.product-images', null);
        if ($disk && ($disk['driver'] ?? null) === 's3') {
            return Storage::disk('product-images')->url($relPath);
        }

        $base = rtrim((string) config('app.url', ''), '/');

        return $base.'/storage/'.ltrim($relPath, '/');
    }
}
