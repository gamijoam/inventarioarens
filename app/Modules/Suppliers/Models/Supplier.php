<?php

namespace App\Modules\Suppliers\Models;

use App\Modules\Products\Concerns\PropagatesCatalogToSpinoffs;
use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'document_type',
    'document_number',
    'phone',
    'email',
    'fiscal_address',
    'notes',
    'is_active',
])]
class Supplier extends Model
{
    use BelongsToTenant, PropagatesCatalogToSpinoffs;

    public const DOCUMENT_V = 'V';

    public const DOCUMENT_E = 'E';

    public const DOCUMENT_J = 'J';

    public const DOCUMENT_G = 'G';

    public const DOCUMENT_P = 'P';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function propagateToSpinoffs(Model $model): void
    {
        $spinoffs = Tenant::query()
            ->where('parent_id', $model->tenant_id)
            ->where('is_group', false)
            ->get();

        $service = app(SharedCatalogPropagationService::class);
        foreach ($spinoffs as $spinoff) {
            $service->ensureSupplierCopyFor($model, $spinoff);
        }
    }
}
