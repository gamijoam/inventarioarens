<?php

namespace App\Modules\Warehouses\Models;

use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'warehouse_id',
    'parent_id',
    'name',
    'code',
    'aisle',
    'rack',
    'bin',
    'level',
    'capacity',
    'is_active',
])]
class WarehouseLocation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function fullPath(): string
    {
        $segments = [$this->name];
        $current = $this->parent;
        while ($current !== null) {
            array_unshift($segments, $current->name);
            $current = $current->parent;
        }

        return implode(' / ', $segments);
    }
}
