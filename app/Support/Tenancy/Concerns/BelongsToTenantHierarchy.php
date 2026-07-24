<?php

namespace App\Support\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait para modelos compartidos por grupo.
 *
 * - En empresas normales se comporta igual que `BelongsToTenant`.
 * - En tenants spinoff, la lectura incluye el tenant actual y su grupo padre.
 * - Los creates se anclan al tenant raiz del grupo para mantener una sola
 *   fuente de verdad en catálogo, precios y configuraciones compartidas.
 */
trait BelongsToTenantHierarchy
{
    public static function bootBelongsToTenantHierarchy(): void
    {
        static::addGlobalScope('tenant_hierarchy', function (Builder $builder): void {
            $tenantIds = app(TenantManager::class)->sharedTenantIds();

            if ($tenantIds === []) {
                return;
            }

            $builder->whereIn($builder->getModel()->qualifyColumn('tenant_id'), $tenantIds);
        });

        static::creating(function ($model): void {
            if (! $model->tenant_id) {
                $model->tenant_id = app(TenantManager::class)->sharedTenantId() ?? app(TenantManager::class)->require()->id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
