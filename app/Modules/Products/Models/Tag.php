<?php

namespace App\Modules\Products\Models;

use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'color'])]
class Tag extends Model
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
            $svc->ensureTagCopyFor($model, $spinoff);
        }
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_tag')
            ->withPivot('tenant_id');
    }
}
