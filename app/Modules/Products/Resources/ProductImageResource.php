<?php

namespace App\Modules\Products\Resources;

use App\Modules\Products\Models\ProductImage;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductImage
 */
class ProductImageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'uuid' => $this->uuid,
            'product_id' => (int) $this->product_id,
            'mime' => $this->mime,
            'size' => (int) $this->size,
            'width' => (int) ($this->width ?? 0),
            'height' => (int) ($this->height ?? 0),
            'alt' => $this->alt,
            'sort' => (int) $this->sort,
            'is_primary' => (bool) $this->is_primary,
            'url' => $this->url(),
            'medium_url' => $this->mediumUrl(),
            'thumb_url' => $this->thumbUrl(),
            'uploaded_at' => optional($this->created_at)->toISOString(),
            'original_name' => $this->original_name,
        ];
    }
}
