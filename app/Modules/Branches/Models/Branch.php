<?php

namespace App\Modules\Branches\Models;

use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Sync\Syncable;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'code', 'slug', 'status'])]
class Branch extends Model
{
    use BelongsToTenant, Syncable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected static function booted(): void
    {
        static::creating(function (Branch $branch): void {
            if (blank($branch->slug)) {
                $branch->slug = self::availableSlug(
                    (string) $branch->name,
                    (string) $branch->code,
                    (int) ($branch->tenant_id ?: app(TenantManager::class)->require()->id),
                );
            }
        });

        static::saving(function (Branch $branch): void {
            if ($branch->isDirty('slug') && filled($branch->slug)) {
                $branch->slug = Str::slug((string) $branch->slug);
            }
        });
    }

    public static function availableSlug(string $name, string $code, int $tenantId, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: Str::slug($code) ?: 'branch';
        $slug = $base;
        $suffix = 2;

        while (static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

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
