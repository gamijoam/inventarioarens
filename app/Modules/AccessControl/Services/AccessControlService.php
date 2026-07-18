<?php

namespace App\Modules\AccessControl\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlService
{
    public const CRITICAL_ADMIN_ROLES = [
        'Owner',
        'Administrador',
    ];

    public const PROTECTED_ROLES = [
        'Owner',
        'Administrador',
        'Gerente',
        'Vendedor',
        'Almacen',
        'Auditor',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function tenantUsers(?object $filters = null): mixed
    {
        $tenant = app(TenantManager::class)->require();

        return $this->applyUserFilters(
            $tenant->users(),
            $filters,
            [$tenant->id]
        )
            ->with('roles.permissions')
            ->orderBy('name');
    }

    public function tenantUser(int $userId): User
    {
        $user = app(TenantManager::class)
            ->require()
            ->users()
            ->with('roles.permissions')
            ->whereKey($userId)
            ->firstOrFail();

        return $user;
    }

    public function organizationUsers(object $filters, int $perPage = 25): LengthAwarePaginator
    {
        $group = $this->ownedGroupForCurrentTenant($filters->user());
        $tenantIds = $this->organizationTenantIds($group);

        $paginator = $this->applyUserFilters(
            User::query()
                ->whereHas('tenants', fn (Builder $query) => $query->whereIn('tenants.id', $tenantIds))
                ->with([
                    'roles.permissions',
                    'tenants' => fn ($query) => $query
                        ->whereIn('tenants.id', $tenantIds)
                        ->orderByDesc('tenants.is_group')
                        ->orderBy('tenants.name'),
                ]),
            $filters,
            $tenantIds
        )
            ->orderBy('name')
            ->paginate($perPage);

        $paginator->getCollection()->each(function (User $user): void {
            $status = $user->tenants
                ->pluck('pivot.status')
                ->contains('active') ? 'active' : ($user->tenants->first()?->pivot?->status ?? 'inactive');

            $user->setAttribute('organization_status', $status);
        });

        return $paginator;
    }

    public function organizationUser(int $userId, object $request): User
    {
        $group = $this->ownedGroupForCurrentTenant($request->user());
        $tenantIds = $this->organizationTenantIds($group);

        $user = User::query()
            ->whereKey($userId)
            ->whereHas('tenants', fn (Builder $query) => $query->whereIn('tenants.id', $tenantIds))
            ->with([
                'roles.permissions',
                'tenants' => fn ($query) => $query
                    ->whereIn('tenants.id', $tenantIds)
                    ->orderByDesc('tenants.is_group')
                    ->orderBy('tenants.name'),
            ])
            ->firstOrFail();

        $status = $user->tenants
            ->pluck('pivot.status')
            ->contains('active') ? 'active' : ($user->tenants->first()?->pivot?->status ?? 'inactive');

        $user->setAttribute('organization_status', $status);

        return $user;
    }

    public function createOrAttachUser(array $data, User $actor): User
    {
        $tenant = app(TenantManager::class)->require();
        $roles = $data['roles'] ?? [];

        $this->ensureRolesExist($roles);

        return DB::transaction(function () use ($tenant, $data, $roles, $actor): User {
            $email = Str::lower($data['email']);
            $user = User::query()->firstOrNew(['email' => $email]);
            $wasExistingUser = $user->exists;

            if (! $user->exists) {
                $user->name = $data['name'];
                $user->password = Hash::make($data['password'] ?? Str::random(32));
                $user->save();
            } elseif (isset($data['name'])) {
                $user->name = $data['name'];
                $user->save();
            }

            $pivot = $user->tenants()->whereKey($tenant->id)->first();
            $oldValues = [
                'existed' => $wasExistingUser,
                'status' => $pivot?->pivot?->status,
                'roles' => $pivot ? $this->userRoleNames($user) : [],
            ];

            if ($pivot) {
                $user->tenants()->updateExistingPivot($tenant->id, ['status' => 'active']);
            } else {
                $user->tenants()->attach($tenant->id, ['status' => 'active']);
            }

            if ($roles !== []) {
                setPermissionsTeamId($tenant->id);
                $user->syncRoles($roles);
            }

            $user = $this->tenantUser($user->id);

            $this->audit->record('access.user.attached', $user, $actor, $oldValues, [
                'email' => $user->email,
                'status' => $user->pivot?->status,
                'roles' => $this->userRoleNames($user),
            ]);

            return $user;
        });
    }

    public function updateUser(User $user, array $data, User $actor): User
    {
        $oldValues = ['name' => $user->name];

        $user->fill($data);
        $user->save();

        $user = $this->tenantUser($user->id);

        $this->audit->record('access.user.updated', $user, $actor, $oldValues, [
            'name' => $user->name,
        ]);

        return $user;
    }

    public function updateStatus(User $user, string $status, User $actor): User
    {
        if ($user->is($actor) && $status !== 'active') {
            throw ValidationException::withMessages([
                'status' => 'No puedes inactivar tu propio usuario.',
            ]);
        }

        $tenant = app(TenantManager::class)->require();
        $oldStatus = $user->pivot?->status;

        if ($status !== 'active' && $this->userHasCriticalAdminRole($user) && $this->activeCriticalAdministratorCount() <= 1) {
            throw ValidationException::withMessages([
                'status' => 'No puedes inactivar el ultimo administrador activo de la empresa.',
            ]);
        }

        $tenant->users()
            ->updateExistingPivot($user->id, ['status' => $status]);

        $user = $this->tenantUser($user->id);

        $this->audit->record('access.user.status_updated', $user, $actor, [
            'status' => $oldStatus,
        ], [
            'status' => $status,
        ]);

        return $user;
    }

    public function updateUserRoles(User $user, array $roles, User $actor): User
    {
        $this->ensureRolesExist($roles);

        setPermissionsTeamId(app(TenantManager::class)->require()->id);
        $oldRoles = $this->userRoleNames($user);

        if (
            array_intersect($oldRoles, self::CRITICAL_ADMIN_ROLES) !== []
            && array_intersect($roles, self::CRITICAL_ADMIN_ROLES) === []
            && $this->activeCriticalAdministratorCount() <= 1
        ) {
            throw ValidationException::withMessages([
                'roles' => 'No puedes quitar el ultimo rol administrador activo de la empresa.',
            ]);
        }

        $user->syncRoles($roles);

        $user = $this->tenantUser($user->id);

        $this->audit->record('access.user.roles_updated', $user, $actor, [
            'roles' => $oldRoles,
        ], [
            'roles' => $this->userRoleNames($user),
        ]);

        return $user;
    }

    public function roles(): mixed
    {
        return Role::query()
            ->where($this->teamColumn(), app(TenantManager::class)->require()->id)
            ->with('permissions')
            ->orderBy('name');
    }

    public function role(int $roleId): Role
    {
        return $this->roles()->whereKey($roleId)->firstOrFail();
    }

    public function createRole(array $data, User $actor): Role
    {
        $tenant = app(TenantManager::class)->require();
        $permissions = $data['permissions'] ?? [];
        $this->ensurePermissionsExist($permissions);

        return DB::transaction(function () use ($tenant, $data, $permissions, $actor): Role {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
                $this->teamColumn() => $tenant->id,
            ]);

            $role->syncPermissions($permissions);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $role = $this->role($role->id);

            $this->audit->record('access.role.created', $role, $actor, null, [
                'name' => $role->name,
                'permissions' => $this->rolePermissions($role),
            ]);

            return $role;
        });
    }

    public function updateRole(Role $role, array $data, User $actor): Role
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true) && isset($data['name']) && $data['name'] !== $role->name) {
            throw ValidationException::withMessages([
                'name' => 'No se puede cambiar el nombre de un rol base del sistema.',
            ]);
        }

        if (isset($data['permissions'])) {
            $this->ensurePermissionsExist($data['permissions']);
        }

        return DB::transaction(function () use ($role, $data, $actor): Role {
            $oldValues = [
                'name' => $role->name,
                'permissions' => $this->rolePermissions($role),
            ];

            $role->fill(collect($data)->only(['name'])->all());
            $role->save();

            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $role = $this->role($role->id);

            $this->audit->record('access.role.updated', $role, $actor, $oldValues, [
                'name' => $role->name,
                'permissions' => $this->rolePermissions($role),
            ]);

            return $role;
        });
    }

    public function updateRolePermissions(Role $role, array $permissions, User $actor): Role
    {
        $this->ensurePermissionsExist($permissions);

        return DB::transaction(function () use ($role, $permissions, $actor): Role {
            $oldValues = ['permissions' => $this->rolePermissions($role)];

            $role->syncPermissions($permissions);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $role = $this->role($role->id);

            $this->audit->record('access.role.permissions_updated', $role, $actor, $oldValues, [
                'permissions' => $this->rolePermissions($role),
            ]);

            return $role;
        });
    }

    public function deleteRole(Role $role, User $actor): void
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'No se puede eliminar un rol base del sistema.',
            ]);
        }

        DB::transaction(function () use ($role, $actor): void {
            $this->audit->record('access.role.deleted', $role, $actor, [
                'name' => $role->name,
                'permissions' => $this->rolePermissions($role),
            ], null);

            $role->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    public function groupedPermissions(): array
    {
        return collect(BasePermissions::PERMISSIONS)
            ->sort()
            ->groupBy(fn (string $permission): string => Str::before($permission, '.'))
            ->map(fn ($permissions, string $module): array => [
                'module' => $module,
                'permissions' => $permissions->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Clona un rol existente (sea base o custom del mismo tenant) en uno nuevo
     * con los mismos permisos. El clon queda como custom del tenant.
     */
    public function duplicateRole(Role $source, string $newName, User $actor): Role
    {
        $tenant = app(TenantManager::class)->require();
        $teamColumn = $this->teamColumn();

        // Quitar el global scope de Spatie para chequear el team_id real del source.
        $sourceTeam = Role::query()->withoutGlobalScopes()
            ->whereKey($source->id)
            ->value($teamColumn);

        if ($sourceTeam === null || (int) $sourceTeam !== (int) $tenant->id) {
            abort(404, 'Rol no pertenece a esta empresa.');
        }

        // Re-cargar con permisos (sin scope para que no filtre por team actual).
        $source = Role::query()->withoutGlobalScopes()
            ->with(['permissions'])
            ->findOrFail($source->id);

        return DB::transaction(function () use ($source, $newName, $actor, $teamColumn, $tenant): Role {
            $clone = Role::create([
                'name' => $newName,
                'guard_name' => 'web',
                $teamColumn => $tenant->id,
            ]);

            $permissions = $source->permissions->pluck('name')->all();
            $clone->syncPermissions($permissions);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $clone = $this->role($clone->id);

            $this->audit->record('access.role.duplicated', $clone, $actor, [
                'source_role_id' => $source->id,
                'source_role_name' => $source->name,
            ], [
                'name' => $clone->name,
                'permission_count' => count($permissions),
            ]);

            return $clone;
        });
    }

    public function userPermissions(User $user): array
    {
        setPermissionsTeamId(app(TenantManager::class)->require()->id);

        return $user->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    private function ensureRolesExist(array $roles): void
    {
        if ($roles === []) {
            return;
        }

        $found = $this->roles()
            ->whereIn('name', $roles)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($roles, $found));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'roles' => 'Los roles no existen en la empresa actual: '.implode(', ', $missing),
            ]);
        }
    }

    private function ensurePermissionsExist(array $permissions): void
    {
        if ($permissions === []) {
            return;
        }

        $allowed = Permission::query()
            ->whereIn('name', $permissions)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($permissions, $allowed));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'Los permisos no existen: '.implode(', ', $missing),
            ]);
        }
    }

    private function teamColumn(): string
    {
        return config('permission.column_names.team_foreign_key', 'team_id');
    }

    private function userRoleNames(User $user): array
    {
        setPermissionsTeamId(app(TenantManager::class)->require()->id);

        return $user->roles()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    private function userHasCriticalAdminRole(User $user): bool
    {
        return array_intersect($this->userRoleNames($user), self::CRITICAL_ADMIN_ROLES) !== [];
    }

    private function activeCriticalAdministratorCount(): int
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $teamColumn = $this->teamColumn();

        return DB::table('tenant_user')
            ->join('model_has_roles', function ($join) use ($tenantId, $teamColumn): void {
                $join->on('tenant_user.user_id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', User::class)
                    ->where("model_has_roles.{$teamColumn}", $tenantId);
            })
            ->join('roles', function ($join) use ($tenantId, $teamColumn): void {
                $join->on('model_has_roles.role_id', '=', 'roles.id')
                    ->where("roles.{$teamColumn}", $tenantId)
                    ->whereIn('roles.name', self::CRITICAL_ADMIN_ROLES);
            })
            ->where('tenant_user.tenant_id', $tenantId)
            ->where('tenant_user.status', 'active')
            ->distinct('tenant_user.user_id')
            ->count('tenant_user.user_id');
    }

    private function rolePermissions(Role $role): array
    {
        return $role->permissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    private function applyUserFilters(mixed $query, ?object $filters, array $tenantIds): mixed
    {
        $search = trim((string) ($filters?->query('search', '') ?? ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner
                    ->where('users.name', 'ilike', "%{$search}%")
                    ->orWhere('users.email', 'ilike', "%{$search}%");
            });
        }

        $status = (string) ($filters?->query('status', 'all') ?? 'all');
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->whereHas('tenants', function (Builder $tenantQuery) use ($tenantIds, $status): void {
                $tenantQuery
                    ->whereIn('tenants.id', $tenantIds)
                    ->where('tenant_user.status', $status);
            });
        }

        $roleId = $filters?->query('role_id');
        if ($roleId !== null && $roleId !== '' && is_numeric($roleId)) {
            $teamColumn = $this->teamColumn();
            $query->whereHas('roles', function (Builder $roleQuery) use ($roleId, $tenantIds, $teamColumn): void {
                $roleQuery
                    ->where('roles.id', (int) $roleId)
                    ->whereIn("roles.{$teamColumn}", $tenantIds);
            });
        }

        return $query;
    }

    private function ownedGroupForCurrentTenant(User $actor): Tenant
    {
        $tenant = app(TenantManager::class)->require();
        $group = $tenant->isGroup()
            ? $tenant
            : $tenant->parent()->firstOrFail();

        abort_unless($actor->isOwnerOf($group), 403);

        return $group;
    }

    private function organizationTenantIds(Tenant $group): array
    {
        return $group
            ->spinoffs()
            ->pluck('id')
            ->prepend($group->id)
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
