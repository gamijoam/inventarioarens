<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'domain', 'status', 'plan', 'parent_id'])]
class Tenant extends Model
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('status')
            ->withTimestamps();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isGroup(): bool
    {
        return $this->parent_id === null;
    }

    public function isSpinoff(): bool
    {
        return $this->parent_id !== null;
    }

    public function isOwnedBy(User $user): bool
    {
        if ($this->isSpinoff()) {
            return false;
        }

        return $user->tenants()
            ->whereKey($this->id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->query();

        if (is_numeric($value)) {
            $query->where('id', (int) $value);
        } else {
            $query->where('slug', $value);
        }

        return $query->firstOrFail();
    }
}