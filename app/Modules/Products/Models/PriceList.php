<?php

namespace App\Modules\Products\Models;

use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'code',
    'description',
    'markup_percentage',
    'payment_exchange_rate_type_id',
    'is_default',
    'is_active',
    'sort_order',
])]
class PriceList extends Model
{
    use BelongsToTenant, PropagatesCatalogToSpinoffs;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'markup_percentage' => 'decimal:2',
        ];
    }

    protected static function propagateToSpinoffs(Model $model): void
    {
        $spinoffs = Tenant::query()
            ->where('parent_id', $model->tenant_id)
            ->where('is_group', false)
            ->get();

        $svc = app(SharedCatalogPropagationService::class);
        foreach ($spinoffs as $spinoff) {
            $svc->ensurePriceListCopyFor($model, $spinoff);
        }
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function paymentExchangeRateType(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class, 'payment_exchange_rate_type_id');
    }

    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'price_list_payment_method', 'price_list_id', 'payment_method_id')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }
}
