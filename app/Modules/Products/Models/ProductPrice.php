<?php

namespace App\Modules\Products\Models;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'price_list_id',
    'price',
    'currency',
    'exchange_rate_type_id',
    'is_active',
])]
class ProductPrice extends Model
{
    use BelongsToTenant, PropagatesCatalogToSpinoffs;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function exchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class);
    }

    protected static function propagateToSpinoffs(Model $model): void
    {
        $model->loadMissing(['product', 'priceList']);
        if (! $model->product?->isCatalogMaster()) {
            return;
        }

        $spinoffs = Tenant::query()
            ->where('parent_id', $model->tenant_id)
            ->where('is_group', false)
            ->get();

        $service = app(SharedCatalogPropagationService::class);
        foreach ($spinoffs as $spinoff) {
            $service->syncProductPriceCopyFor($model, $spinoff);
        }
    }
}
