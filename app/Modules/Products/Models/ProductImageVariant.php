<?php

namespace App\Modules\Products\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una variante de `ProductImage` (original / medium / thumb).
 *
 * Vive en su propia tabla (no en `product_images`) para:
 *  - Replicar via sync sin duplicar 3x el evento (una variante faltante es recuperable).
 *  - Limpiar storage en cascada al soft-delete de la imagen padre.
 *  - Diagnosticar rapidamente cual variante falla sin leer el archivo en disco.
 */
#[Fillable([
    'product_image_id',
    'variant',
    'storage_path',
    'mime',
    'size',
    'width',
    'height',
])]
class ProductImageVariant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'product_image_variants';

    public const VARIANT_ORIGINAL = 'original';

    public const VARIANT_MEDIUM = 'medium';

    public const VARIANT_THUMB = 'thumb';

    public const ALL_VARIANTS = [self::VARIANT_ORIGINAL, self::VARIANT_MEDIUM, self::VARIANT_THUMB];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function productImage(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class);
    }

    public function isOriginal(): bool
    {
        return $this->variant === self::VARIANT_ORIGINAL;
    }
}
