<?php

namespace App\Modules\Products\Models;

use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'is_active'])]
class Brand extends Model
{
    use BelongsToTenant, PropagatesCatalogToSpinoffs;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function propagateToSpinoffs(Model $model): void
    {
        try {
            $spinoffs = Tenant::query()
                ->where('parent_id', $model->tenant_id)
                ->where('is_group', false)
                ->get();
        } catch (\Throwable) {
            return;
        }

        $svc = app(SharedCatalogPropagationService::class);
        foreach ($spinoffs as $spinoff) {
            try {
                $svc->ensureBrandCopyFor($model, $spinoff);
            } catch (\Throwable) {
                // Continuar con el siguiente spinoff. No interrumpir la
                // operacion principal.
            }
        }
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
