<?php

namespace App\Modules\Branches\Models;

use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'status'])]
class Branch extends Model
{
    use BelongsToTenant;

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
