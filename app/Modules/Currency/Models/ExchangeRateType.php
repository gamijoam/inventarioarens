<?php

namespace App\Modules\Currency\Models;

use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'is_default', 'is_active'])]
class ExchangeRateType extends Model
{
    use BelongsToTenant, PropagatesCatalogToSpinoffs;

    protected static function propagateToSpinoffs(Model $model): void
    {
        $spinoffs = Tenant::query()
            ->where('parent_id', $model->tenant_id)
            ->where('is_group', false)
            ->get();

        $svc = app(SharedCatalogPropagationService::class);
        foreach ($spinoffs as $spinoff) {
            $copy = $svc->ensureExchangeRateTypeCopyFor($model, $spinoff);
            foreach ($model->rates()->withoutGlobalScopes()->get() as $rate) {
                $svc->ensureExchangeRateCopyFor($rate, $spinoff, $copy->id);
            }
        }
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }
}
