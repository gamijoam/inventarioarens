<?php

namespace App\Modules\AccessControl\Services;

use App\Models\User;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlService
{
    public const PROTECTED_ROLES = [
        'Owner',
        'Administrador',
        'Gerente',
        'Vendedor',
        'Almacen',
        'Auditor',
    ];

    public function tenantUsers(): mixed
    {
        return app(TenantManager::class)
            ->require()
            ->users()
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

    public function createOrAttachUser(array $data): User
    {
        $tenant = app(TenantManager::class)->require();
        $roles = $data['roles'] ?? [];

        $this->ensureRolesExist($roles);

        return DB::transaction(function () use ($tenant, $data, $roles): User {
            $email = Str::lower($data['email']);
            $user = User::query()->firstOrNew(['email' => $email]);

            if (! $user->exists) {
                $user->name = $data['name'];
                $user->password = Hash::make($data['password'] ?? Str::random(32));
                $user->save();
            } elseif (isset($data['name'])) {
                $user->name = $data['name'];
                $user->save();
            }

            $pivot = $user->tenants()->whereKey($tenant->id)->first();

            if ($pivot) {
                $user->tenants()->updateExistingPivot($tenant->id, ['status' => 'active']);
            } else {
                $user->tenants()->attach($tenant->id, ['status' => 'active']);
            }

            if ($roles !== []) {
                setPermissionsTeamId($tenant->id);
                $user->syncRoles($roles);
            }

            return $this->tenantUser($user->id);
        });
    }

    public function updateUser(User $user, array $data): User
    {
        $user->fill($data);
        $user->save();

        return $this->tenantUser($user->id);
    }

    public function updateStatus(User $user, string $status, User $actor): User
    {
        if ($user->is($actor) && $status !== 'active') {
            throw ValidationException::withMessages([
                'status' => 'No puedes inactivar tu propio usuario.',
            ]);
        }

        app(TenantManager::class)
            ->require()
            ->users()
            ->updateExistingPivot($user->id, ['status' => $status]);

        return $this->tenantUser($user->id);
    }

    public function updateUserRoles(User $user, array $roles): User
    {
        $this->ensureRolesExist($roles);

        setPermissionsTeamId(app(TenantManager::class)->require()->id);
        $user->syncRoles($roles);

        return $this->tenantUser($user->id);
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

    public function createRole(array $data): Role
    {
        $tenant = app(TenantManager::class)->require();
        $permissions = $data['permissions'] ?? [];
        $this->ensurePermissionsExist($permissions);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'web',
            $this->teamColumn() => $tenant->id,
        ]);

        $role->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->role($role->id);
    }

    public function updateRole(Role $role, array $data): Role
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true) && isset($data['name']) && $data['name'] !== $role->name) {
            throw ValidationException::withMessages([
                'name' => 'No se puede cambiar el nombre de un rol base del sistema.',
            ]);
        }

        if (isset($data['permissions'])) {
            $this->ensurePermissionsExist($data['permissions']);
        }

        $role->fill(collect($data)->only(['name'])->all());
        $role->save();

        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->role($role->id);
    }

    public function updateRolePermissions(Role $role, array $permissions): Role
    {
        $this->ensurePermissionsExist($permissions);
        $role->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->role($role->id);
    }

    public function deleteRole(Role $role): void
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'No se puede eliminar un rol base del sistema.',
            ]);
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
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
}
