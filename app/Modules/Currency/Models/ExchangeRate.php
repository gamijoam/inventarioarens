<?php

namespace App\Modules\Currency\Models;

use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'exchange_rate_type_id',
    'base_currency',
    'quote_currency',
    'rate',
    'effective_at',
    'is_active',
    'source',
])]
class ExchangeRate extends Model
{
    use BelongsToTenant, PropagatesCatalogToSpinoffs;

    public const BASE_USD = 'USD';

    public const QUOTE_VES = 'VES';

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'is_active' => 'boolean',
            'rate' => 'decimal:6',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateType::class, 'exchange_rate_type_id');
    }

    protected static function propagateToSpinoffs(EloquentModel $model): void
    {
        $model->loadMissing('type');
        if (! $model->type) {
            return;
        }

        $spinoffs = Tenant::query()
            ->where('parent_id', $model->tenant_id)
            ->where('is_group', false)
            ->get();

        $service = app(SharedCatalogPropagationService::class);
        foreach ($spinoffs as $spinoff) {
            $localType = $service->ensureExchangeRateTypeCopyFor($model->type, $spinoff);
            $service->ensureExchangeRateCopyFor($model, $spinoff, $localType->id);
        }
    }
}
