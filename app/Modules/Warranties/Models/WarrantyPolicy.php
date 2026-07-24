<?php

namespace App\Modules\Warranties\Models;

use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'duration_days',
    'coverage_type',
    'conditions',
    'is_active',
])]
class WarrantyPolicy extends Model
{
    use BelongsToTenant, PropagatesCatalogToSpinoffs;

    public const COVERAGE_STORE = 'store';

    public const COVERAGE_MANUFACTURER = 'manufacturer';

    public const COVERAGE_NONE = 'none';

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'is_active' => 'boolean',
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
            $svc->ensureWarrantyPolicyCopyFor($model, $spinoff);
        }
    }
}
