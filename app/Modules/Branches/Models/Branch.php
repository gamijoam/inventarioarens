<?php

namespace App\Modules\Branches\Models;

use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Sync\Syncable;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'code',
    'status',
    'fiscal_address',
    'fiscal_city',
    'fiscal_state',
    'fiscal_phone',
    'fiscal_email',
    'tax_condition',
])]
class Branch extends Model
{
    use BelongsToTenant, Syncable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected function syncOutboxMethod(string $action): ?string
    {
        return match ($action) {
            'created' => 'branchCreated',
            'updated' => 'branchUpdated',
            default => null,
        };
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
