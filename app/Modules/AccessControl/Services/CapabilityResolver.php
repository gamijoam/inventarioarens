<?php

namespace App\Modules\AccessControl\Services;

use App\Models\User;
use App\Modules\AccessControl\Models\UserPermissionOverride;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CapabilityResolver
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Devuelve los permisos efectivos de un user:
     *   effective = (union de perms de todos los roles del user en el tenant)
     *                UNION (extras con effect='allow')
     *                MINUS (denies con effect='deny')
     *
     * Tambien retorna las listas de extras y denies para que la UI muestre
     * el "delta" vs el set base del rol.
     */
    public function resolveFor(User $user): array
    {
        $tenant = app(TenantManager::class)->require();

        // Resetear el team activo del contexto al tenant actual.
        setPermissionsTeamId($tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Permisos de los roles del user.
        $basePermissions = $this->permissionsFor($user, $tenant->id);

        // 2. Overrides del user en este tenant.
        $overrides = UserPermissionOverride::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('permission');

        $extras = [];
        $denies = [];

        foreach ($overrides as $override) {
            if ($override->effect === UserPermissionOverride::EFFECT_ALLOW) {
                $extras[] = $override->permission;
            } else {
                $denies[] = $override->permission;
            }
        }

        $effective = $basePermissions;
        foreach ($extras as $permission) {
            $effective[$permission] = true;
        }
        foreach ($denies as $permission) {
            unset($effective[$permission]);
        }

        ksort($effective);

        $scopeStatus = app(\App\Modules\AccessControl\Services\ScopeResolver::class)->statusFor($user);

        return [
            'permissions' => array_keys($effective),
            'permission_count' => count($effective),
            'base_permissions' => array_keys($basePermissions),
            'base_count' => count($basePermissions),
            'extras' => $extras,
            'denied' => $denies,
            'roles' => $this->rolesFor($user, $tenant->id),
            'scope_status' => $scopeStatus,
        ];
    }

    /**
     * Aplica los overrides del user a una lista de permisos base.
     * Util para el resolver de Resources (masking a nivel campo).
     */
    public function applyOverrides(User $user, array $basePermissions): array
    {
        $tenant = app(TenantManager::class)->require();
        setPermissionsTeamId($tenant->id);

        $effective = array_flip($basePermissions);

        $overrides = UserPermissionOverride::query()
            ->where('user_id', $user->id)
            ->get();

        foreach ($overrides as $override) {
            if ($override->effect === UserPermissionOverride::EFFECT_ALLOW) {
                $effective[$override->permission] = true;
            } else {
                unset($effective[$override->permission]);
            }
        }

        return array_keys($effective);
    }

    /**
     * Reemplaza TODOS los overrides del user en el tenant actual por la lista provista.
     * Idempotente. Registra audit log.
     */
    public function replaceOverrides(User $user, array $items, ?\App\Models\User $actor = null): void
    {
        $tenant = app(TenantManager::class)->require();
        setPermissionsTeamId($tenant->id);

        DB::transaction(function () use ($user, $tenant, $items): void {
            // Borrar overrides existentes del user en este tenant.
            UserPermissionOverride::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->delete();

            // Insertar los nuevos.
            foreach ($items as $item) {
                $permission = $item['permission'] ?? null;
                $effect = $item['effect'] ?? null;
                if (! $permission || ! in_array($effect, UserPermissionOverride::EFFECTS, true)) {
                    continue;
                }
                UserPermissionOverride::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'effect' => $effect,
                ]);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        $this->audit->record('access.user.overrides_replaced', $user, $actor ?? $user, null, [
            'tenant_id' => $tenant->id,
            'overrides_count' => count($items),
        ]);
    }

    public function removeOverride(User $user, string $permission, ?\App\Models\User $actor = null): void
    {
        $tenant = app(TenantManager::class)->require();
        setPermissionsTeamId($tenant->id);

        UserPermissionOverride::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('permission', $permission)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->audit->record('access.user.override_removed', $user, $actor ?? $user, null, [
            'tenant_id' => $tenant->id,
            'permission' => $permission,
        ]);
    }

    private function permissionsFor(User $user, int $tenantId): array
    {
        // Roles del user en este tenant (sin global scope).
        $roleIds = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where(config('permission.column_names.team_foreign_key', 'team_id'), $tenantId)
            ->pluck('role_id')
            ->all();

        if (empty($roleIds)) {
            return [];
        }

        $permissions = DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->pluck('permissions.name')
            ->unique()
            ->all();

        return array_fill_keys($permissions, true);
    }

    private function rolesFor(User $user, int $tenantId): array
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');
        $roleIds = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where($teamColumn, $tenantId)
            ->pluck('role_id')
            ->all();

        if (empty($roleIds)) {
            return [];
        }

        return Role::query()->whereIn('id', $roleIds)->pluck('name')->all();
    }
}