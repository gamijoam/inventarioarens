<?php

namespace App\Support\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Scopes\TenantScope;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model): void {
            if (! $model->tenant_id) {
                $model->tenant_id = app(TenantManager::class)->require()->id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
