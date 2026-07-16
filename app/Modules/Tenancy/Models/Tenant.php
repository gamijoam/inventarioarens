<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'domain', 'status', 'plan', 'parent_id', 'is_group'])]
class Tenant extends Model
{
    protected $casts = [
        'is_group' => 'boolean',
        'parent_id' => 'integer',
    ];

    /**
     * Auto-deriva `is_group` segun `parent_id` al crear:
     *  - parent_id = null  => is_group = true (raiz)
     *  - parent_id != null => is_group = false (spinoff)
     *
     * Esto evita que codigo legacy o tests que olvidan setear is_group
     * terminen creando filas inconsistentes. Los callers pueden sobreescribir
     * el valor pasando `is_group` explicitamente.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            if (! array_key_exists('is_group', $tenant->getAttributes())) {
                $tenant->is_group = $tenant->parent_id === null;
            }
        });
    }

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

    public function scopeGroups(Builder $query): Builder
    {
        return $query->where('is_group', true);
    }

    public function scopeSpinoffs(Builder $query): Builder
    {
        return $query->where('is_group', false)->whereNotNull('parent_id');
    }

    public function isGroup(): bool
    {
        return (bool) $this->is_group;
    }

    public function isSpinoff(): bool
    {
        return ! $this->is_group && $this->parent_id !== null;
    }

    public function isOwnedBy(User $user): bool
    {
        return $user->isOwnerOf($this);
    }

    public function spinoffs(): HasMany
    {
        return $this->children()->where('is_group', false);
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