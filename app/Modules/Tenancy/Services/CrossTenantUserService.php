<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CrossTenantUserService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Lista usuarios del tenant.
     *
     * @param  'tenant'|'organization'  $scope
     *         - 'tenant' (default): solo usuarios del tenant especifico.
     *         - 'organization': usuarios del grupo + todos sus spinoffs.
     *           Solo valido si el $tenant es un grupo o si tiene parent_id.
     */
    public function listUsers(Tenant $tenant, string $scope = 'tenant'): mixed
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        if ($scope === 'organization') {
            $tenantIds = $this->resolveOrganizationTenantIds($tenant);

            return User::query()
                ->whereHas('tenants', function ($query) use ($tenantIds): void {
                    $query->whereIn('tenant_user.tenant_id', $tenantIds);
                })
                ->with(['roles' => function ($query) use ($teamColumn, $tenantIds): void {
                    $query->whereIn("roles.{$teamColumn}", $tenantIds);
                }, 'tenants' => function ($query) use ($tenantIds): void {
                    $query->whereIn('tenants.id', $tenantIds)
                        ->select('tenants.id', 'tenants.name', 'tenants.slug', 'tenants.is_group');
                }])
                ->orderBy('name')
                ->paginate(25);
        }

        return $tenant->users()
            ->with(['roles' => function ($query) use ($teamColumn, $tenant): void {
                $query->where("roles.{$teamColumn}", $tenant->id);
            }])
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * Si $tenant es un grupo: retorna [group_id, spinoff_1, spinoff_2, ...].
     * Si $tenant es un spinoff: retorna [parent_group_id, ...spinoffs del parent, tenant_id].
     */
    private function resolveOrganizationTenantIds(Tenant $tenant): array
    {
        $root = $tenant->isGroup() ? $tenant : $tenant->parent;
        if (! $root) {
            return [$tenant->id];
        }

        $ids = [$root->id];
        $spinoffIds = $root->spinoffs()->pluck('id')->all();

        return array_values(array_unique(array_merge($ids, $spinoffIds)));
    }

    /**
     * Asocia un usuario a un tenant. Si el usuario no existe, lo crea.
     * Si se pasan roles, los asigna al usuario dentro del tenant (con team_id).
     */
    public function attachUser(Tenant $tenant, array $data, User $actor): User
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');
        $email = isset($data['email']) ? Str::lower($data['email']) : null;
        $userId = $data['user_id'] ?? null;
        $roles = $data['roles'] ?? [];
        $status = $data['status'] ?? 'active';

        $user = $userId
            ? User::query()->findOrFail($userId)
            : User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->name = $data['name'] ?? $email ?? 'User';
            $user->password = Hash::make($data['password'] ?? Str::random(32));
            $user->save();
        }

        $pivot = $user->tenants()->whereKey($tenant->id)->first();

        $oldValues = [
            'existed' => $user->exists,
            'status' => $pivot?->pivot?->status,
        ];

        if ($pivot) {
            $user->tenants()->updateExistingPivot($tenant->id, ['status' => $status]);
        } else {
            $user->tenants()->attach($tenant->id, ['status' => $status]);
        }

        if ($roles !== []) {
            $this->ensureRolesExist($tenant, $roles);
            $this->withTenantContext($tenant, function () use ($user, $roles): void {
                $user->syncRoles($roles);
            });
        }

        $user->load(['roles' => function ($query) use ($teamColumn, $tenant): void {
            $query->where("roles.{$teamColumn}", $tenant->id);
        }]);

        $this->withTenantContext($tenant, function () use ($tenant, $user, $actor, $oldValues, $status, $roles): void {
            $this->audit->record('tenant.user_attached', $user, $actor, $oldValues, [
                'tenant_slug' => $tenant->slug,
                'email' => $user->email,
                'status' => $status,
                'roles' => $roles,
            ]);
        });

        return $user;
    }

    public function detachUser(Tenant $tenant, User $user, User $actor): void
    {
        $pivot = $user->tenants()->whereKey($tenant->id)->first();

        if (! $pivot) {
            throw ValidationException::withMessages([
                'user' => "El usuario no pertenece a la empresa {$tenant->slug}.",
            ]);
        }

        $oldStatus = $pivot->pivot->status;

        $this->withTenantContext($tenant, function () use ($tenant, $user): void {
            DB::transaction(function () use ($tenant, $user): void {
                $user->roles()->detach();
                $user->tenants()->detach($tenant->id);
            });
        });

        $this->withTenantContext($tenant, function () use ($tenant, $user, $actor, $oldStatus): void {
            $this->audit->record('tenant.user_detached', $user, $actor, [
                'tenant_slug' => $tenant->slug,
                'old_status' => $oldStatus,
            ], null);
        });
    }

    private function withTenantContext(Tenant $tenant, \Closure $callback): void
    {
        $manager = app(TenantManager::class);
        $previous = $manager->current();
        $previousTeamId = function_exists('getPermissionsTeamId') ? \getPermissionsTeamId() : null;

        $manager->set($tenant);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            $callback();
        } finally {
            if ($previous) {
                $manager->set($previous);
                if ($previousTeamId !== null && function_exists('setPermissionsTeamId')) {
                    setPermissionsTeamId($previousTeamId);
                }
            } else {
                $manager->clear();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function ensureRolesExist(Tenant $tenant, array $roles): void
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        $existing = Role::query()
            ->whereIn('name', $roles)
            ->where($teamColumn, $tenant->id)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($roles, $existing));

        foreach ($missing as $roleName) {
            if (array_key_exists($roleName, BasePermissions::ROLE_PERMISSIONS)) {
                $role = Role::create([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    $teamColumn => $tenant->id,
                ]);
                $perms = Permission::query()
                    ->whereIn('name', BasePermissions::ROLE_PERMISSIONS[$roleName])
                    ->where('guard_name', 'web')
                    ->get();
                $role->syncPermissions($perms);
            } else {
                throw ValidationException::withMessages([
                    'roles' => "El rol '{$roleName}' no existe en la empresa {$tenant->slug} y no es un rol base del sistema.",
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}