<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'warehouse_id',
    'product_id',
    'product_variant_id',
    'lot_code',
    'expires_on',
    'lot_key',
    'quantity',
    'unit_cost',
    'received_at',
])]
class StockLot extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'received_at' => 'datetime',
        ];
    }

    public static function keyFor(?int $variantId, ?string $lotCode, string $expiresOn): string
    {
        return ($variantId ?? 0).'|'.trim((string) $lotCode).'|'.$expiresOn;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
