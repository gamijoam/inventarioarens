<?php

namespace App\Models;

use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use App\Modules\Auth\Models\AuthToken;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_platform_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot('status')
            ->withTimestamps();
    }

    public function authTokens(): HasMany
    {
        return $this->hasMany(AuthToken::class);
    }

    public function belongsToTenant(Tenant|int $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->tenants()
            ->whereKey($tenantId)
            ->wherePivot('status', 'active')
            ->exists();
    }

    public function scopePlatformAdmins(Builder $query): Builder
    {
        return $query->where('is_platform_admin', true);
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    /**
     * Determina si el user es Owner de un grupo (jerarquia explicita).
     *
     * Logica:
     *  1. El tenant debe ser un grupo (is_group=true).
     *  2. El user debe ser member active del grupo (status='active' en pivote).
     *  3. El user debe tener CUALQUIER rol dentro del grupo (no necesariamente
     *     "Owner"). Esto es para back-compat con codigo legacy que usaba
     *     roles con nombres custom (Owner-{uniqid}, etc).
     *
     * Para verificacion estricta del rol "Owner", usar isStrictOwnerOf().
     */
    public function isOwnerOf(Tenant $group): bool
    {
        if (! $group->isGroup()) {
            return false;
        }

        if (! $this->belongsToTenant($group)) {
            return false;
        }

        return $this->hasAnyRoleInGroup($group);
    }

    /**
     * Version estricta: el user debe tener especificamente el rol "Owner" del grupo.
     */
    public function isStrictOwnerOf(Tenant $group): bool
    {
        if (! $this->isOwnerOf($group)) {
            return false;
        }

        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', self::class)
            ->where('model_has_roles.model_id', $this->id)
            ->where('roles.name', 'Owner')
            ->where('roles.tenant_id', $group->id)
            ->where('roles.guard_name', 'web')
            ->exists();
    }

    private function hasAnyRoleInGroup(Tenant $group): bool
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', self::class)
            ->where('model_has_roles.model_id', $this->id)
            ->where('roles.tenant_id', $group->id)
            ->where('roles.guard_name', 'web')
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }
}