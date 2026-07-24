<?php

namespace App\Modules\Products\Models;

use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'name', 'slug', 'description', 'sort_order', 'is_active'])]
class Category extends Model
{
    use BelongsToTenant, PropagatesCatalogToSpinoffs;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function propagateToSpinoffs(Model $model): void
    {
        // Las categorias con jerarquia se procesan en orden topologico
        // (padres antes que hijos) via el servicio de propagacion.
        $group = Tenant::query()->withoutGlobalScopes()->find($model->tenant_id);
        if (! $group || ! $group->isGroup()) {
            return;
        }

        $spinoffs = Tenant::query()
            ->where('parent_id', $group->id)
            ->where('is_group', false)
            ->get();

        $svc = app(SharedCatalogPropagationService::class);
        foreach ($spinoffs as $spinoff) {
            $svc->propagateSingleCategoryToSpinoff($model, $spinoff);
        }
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_category')
            ->withPivot('tenant_id');
    }
}
