<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branches\Models\Branch;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TenantRegistrationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Crea una empresa (spinoff) DENTRO de un grupo existente.
     *
     * Por diseno, una empresa SIEMPRE pertenece a un grupo. El campo
     * `parent_id` es obligatorio y debe apuntar a un tenant con is_group=true.
     *
     * Si el caller NO es platform-admin y quiere crear su propia organizacion
     * (es decir, un grupo raiz con su empresa inicial), debe usar
     * `registerGroupWithInitialTenant()` o la ruta equivalente.
     */
    public function register(array $data, User $actor): Tenant
    {
        $slug = Str::slug((string) $data['slug']);

        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => "Ya existe una empresa con el slug '{$slug}'.",
            ]);
        }

        // parent_group_id es obligatorio: una empresa sin grupo es un modelo
        // invalido a partir de esta fase.
        $parentGroupId = $data['parent_group_id'] ?? null;
        if ($parentGroupId === null) {
            throw ValidationException::withMessages([
                'parent_group_id' => 'Toda empresa debe pertenecer a un grupo. Especifique parent_group_id.',
            ]);
        }

        $parentGroup = Tenant::query()->find($parentGroupId);
        if (! $parentGroup || ! $parentGroup->isGroup()) {
            throw ValidationException::withMessages([
                'parent_group_id' => "El parent_group_id '{$parentGroupId}' no existe o no es un grupo raiz.",
            ]);
        }

        return DB::transaction(function () use ($data, $slug, $actor, $parentGroup): Tenant {
            $tenantManager = app(TenantManager::class);
            $previousTenant = $tenantManager->current();
            $previousTeamId = function_exists('getPermissionsTeamId') ? \getPermissionsTeamId() : null;

            try {
                $tenant = Tenant::query()->create([
                    'name' => $data['name'],
                    'slug' => $slug,
                    'domain' => $data['domain'] ?? null,
                    'status' => 'active',
                    'plan' => $data['plan'] ?? 'standard',
                    'parent_id' => $parentGroup->id,
                    'is_group' => false,
                ]);

                $tenantManager->set($tenant);
                setPermissionsTeamId($tenant->id);
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                $branch = null;
                if (! empty($data['branch'])) {
                    $branch = Branch::query()->create([
                        'name' => $data['branch']['name'],
                        'code' => $data['branch']['code'],
                        'status' => Branch::STATUS_ACTIVE,
                    ]);
                }

                if (! empty($data['warehouse']) && $branch) {
                    Warehouse::query()->create([
                        'branch_id' => $branch->id,
                        'name' => $data['warehouse']['name'],
                        'code' => $data['warehouse']['code'],
                        'status' => Warehouse::STATUS_ACTIVE,
                    ]);
                }

                if (! empty($data['exchange_rate_type'])) {
                    ExchangeRateType::query()->create([
                        'code' => $data['exchange_rate_type']['code'],
                        'name' => $data['exchange_rate_type']['name'],
                        'is_default' => true,
                        'is_active' => true,
                    ]);
                }

                $admin = $this->upsertAdmin($tenant, $data['admin']);

                $this->audit->record('tenant.created', $tenant, $actor, null, [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'parent_group_id' => $parentGroup->id,
                    'parent_group_slug' => $parentGroup->slug,
                    'admin_email' => $admin->email,
                    'branch_code' => $branch?->code,
                    'warehouse_code' => $data['warehouse']['code'] ?? null,
                    'exchange_rate_type_code' => $data['exchange_rate_type']['code'] ?? null,
                ]);

                return $tenant->refresh();
            } finally {
                if ($previousTenant) {
                    $tenantManager->set($previousTenant);
                    if ($previousTeamId !== null && function_exists('setPermissionsTeamId')) {
                        setPermissionsTeamId($previousTeamId);
                    }
                } else {
                    $tenantManager->clear();
                }
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
    }

    /**
     * Crea un grupo raiz Y su empresa inicial en una sola transaccion.
     * Pensado para el flujo del "owner real": un user que se registra y
     * automaticamente obtiene su grupo + su primera empresa.
     *
     * El admin (owner) queda como:
     *  - Member active del grupo (rol Owner)
     *  - Member active de la empresa (rol Administrador)
     * Asi tiene visibilidad y administracion de ambas cosas.
     */
    public function registerGroupWithInitialTenant(array $data, User $actor): array
    {
        $groupSlug = Str::slug((string) $data['group']['slug']);
        $tenantSlug = Str::slug((string) $data['tenant']['slug']);

        if (Tenant::query()->where('slug', $groupSlug)->exists()) {
            throw ValidationException::withMessages([
                'group.slug' => "Ya existe un tenant con el slug '{$groupSlug}'.",
            ]);
        }
        if (Tenant::query()->where('slug', $tenantSlug)->exists()) {
            throw ValidationException::withMessages([
                'tenant.slug' => "Ya existe un tenant con el slug '{$tenantSlug}'.",
            ]);
        }

        return DB::transaction(function () use ($data, $groupSlug, $tenantSlug, $actor): array {
            $tenantManager = app(TenantManager::class);
            $previousTenant = $tenantManager->current();
            $previousTeamId = function_exists('getPermissionsTeamId') ? \getPermissionsTeamId() : null;

            try {
                // 1) Crear grupo (tenant raiz con is_group=true).
                $group = Tenant::query()->create([
                    'name' => $data['group']['name'],
                    'slug' => $groupSlug,
                    'status' => 'active',
                    'plan' => $data['group']['plan'] ?? 'enterprise',
                    'parent_id' => null,
                    'is_group' => true,
                ]);

                // 2) Crear la empresa inicial como spinoff del grupo.
                $tenantManager->set($group);
                setPermissionsTeamId($group->id);
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                // Branch + warehouse + rate inicial dentro del grupo.
                $branch = null;
                if (! empty($data['tenant']['branch'])) {
                    $branch = Branch::query()->create([
                        'name' => $data['tenant']['branch']['name'],
                        'code' => $data['tenant']['branch']['code'],
                        'status' => Branch::STATUS_ACTIVE,
                    ]);
                }
                if (! empty($data['tenant']['warehouse']) && $branch) {
                    Warehouse::query()->create([
                        'branch_id' => $branch->id,
                        'name' => $data['tenant']['warehouse']['name'],
                        'code' => $data['tenant']['warehouse']['code'],
                        'status' => Warehouse::STATUS_ACTIVE,
                    ]);
                }
                if (! empty($data['tenant']['exchange_rate_type'])) {
                    ExchangeRateType::query()->create([
                        'code' => $data['tenant']['exchange_rate_type']['code'],
                        'name' => $data['tenant']['exchange_rate_type']['name'],
                        'is_default' => true,
                        'is_active' => true,
                    ]);
                }

                // Crear el tenant spinoff.
                $tenant = Tenant::query()->create([
                    'name' => $data['tenant']['name'],
                    'slug' => $tenantSlug,
                    'domain' => $data['tenant']['domain'] ?? null,
                    'status' => 'active',
                    'plan' => $data['tenant']['plan'] ?? 'standard',
                    'parent_id' => $group->id,
                    'is_group' => false,
                ]);

                // Permisos base.
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                foreach (BasePermissions::PERMISSIONS as $permission) {
                    Permission::findOrCreate($permission, 'web');
                }

                $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');
                $email = Str::lower($data['admin']['email']);
                $user = User::query()->firstOrNew(['email' => $email]);
                if (! $user->exists) {
                    $user->name = $data['admin']['name'];
                    $user->password = Hash::make($data['admin']['password'] ?? Str::random(32));
                    $user->save();
                }

                // Attach al grupo + a la empresa.
                $user->tenants()->syncWithoutDetaching([
                    $group->id => ['status' => 'active'],
                    $tenant->id => ['status' => 'active'],
                ]);

                if (! empty($data['admin']['password']) && strlen($data['admin']['password']) >= 8) {
                    $user->password = Hash::make($data['admin']['password']);
                    $user->save();
                }

                // Asignar rol Owner al grupo.
                $ownerRole = Role::query()
                    ->where('name', 'Owner')
                    ->where($teamColumn, $group->id)
                    ->first();
                if (! $ownerRole) {
                    $ownerRole = Role::create([
                        'name' => 'Owner',
                        'guard_name' => 'web',
                        $teamColumn => $group->id,
                    ]);
                }
                $allPerms = Permission::query()->whereIn('name', BasePermissions::PERMISSIONS)->where('guard_name', 'web')->get();
                $ownerRole->syncPermissions($allPerms);
                setPermissionsTeamId($group->id);
                $user->assignRole($ownerRole);

                // Asignar rol Administrador a la empresa.
                $adminRole = Role::query()
                    ->where('name', 'Administrador')
                    ->where($teamColumn, $tenant->id)
                    ->first();
                if (! $adminRole) {
                    $adminRole = Role::create([
                        'name' => 'Administrador',
                        'guard_name' => 'web',
                        $teamColumn => $tenant->id,
                    ]);
                }
                $adminRole->syncPermissions($allPerms);
                setPermissionsTeamId($tenant->id);
                $user->assignRole($adminRole);

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                $this->audit->record('tenant_group.with_initial_tenant_created', $group, $actor, null, [
                    'group_slug' => $group->slug,
                    'tenant_slug' => $tenant->slug,
                    'owner_email' => $user->email,
                ]);

                return ['group' => $group->refresh(), 'tenant' => $tenant->refresh()];
            } finally {
                if ($previousTenant) {
                    $tenantManager->set($previousTenant);
                    if ($previousTeamId !== null && function_exists('setPermissionsTeamId')) {
                        setPermissionsTeamId($previousTeamId);
                    }
                } else {
                    $tenantManager->clear();
                }
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
    }

    public function update(Tenant $tenant, array $data, User $actor): Tenant
    {
        $oldValues = [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'domain' => $tenant->domain,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
        ];

        $tenant->fill(collect($data)->only(['name', 'slug', 'domain', 'status', 'plan'])->all());
        $tenant->save();

        $this->auditWithTenantContext($tenant, function () use ($tenant, $actor, $oldValues): void {
            $this->audit->record('tenant.updated', $tenant, $actor, $oldValues, [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'status' => $tenant->status,
                'plan' => $tenant->plan,
            ]);
        });

        return $tenant;
    }

    public function deactivate(Tenant $tenant, User $actor): Tenant
    {
        if ($tenant->status === 'inactive') {
            return $tenant;
        }

        $tenant->update(['status' => 'inactive']);

        $this->auditWithTenantContext($tenant, function () use ($tenant, $actor): void {
            $this->audit->record('tenant.deactivated', $tenant, $actor, [
                'status' => 'active',
            ], [
                'status' => 'inactive',
            ]);
        });

        return $tenant;
    }

    private function auditWithTenantContext(Tenant $tenant, \Closure $callback): void
    {
        $manager = app(TenantManager::class);
        $previous = $manager->current();
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
                if (function_exists('setPermissionsTeamId')) {
                    setPermissionsTeamId($previous->id);
                }
            } else {
                $manager->clear();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function upsertAdmin(Tenant $tenant, array $adminData): User
    {
        $email = Str::lower($adminData['email']);
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->name = $adminData['name'];
            $user->password = Hash::make($adminData['password'] ?? Str::random(32));
            $user->save();
        } elseif (! empty($adminData['name'])) {
            $user->name = $adminData['name'];
            $user->save();
        }

        if (! empty($adminData['password']) && strlen($adminData['password']) >= 8) {
            $user->password = Hash::make($adminData['password']);
            $user->save();
        }

        $user->tenants()->syncWithoutDetaching([
            $tenant->id => ['status' => 'active'],
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::query()
            ->where('name', 'Administrador')
            ->where(config('permission.column_names.team_foreign_key', 'team_id'), $tenant->id)
            ->first();

        if (! $role) {
            $role = Role::create([
                'name' => 'Administrador',
                'guard_name' => 'web',
                config('permission.column_names.team_foreign_key', 'team_id') => $tenant->id,
            ]);
        }

        $permissions = Permission::query()
            ->whereIn('name', BasePermissions::PERMISSIONS)
            ->where('guard_name', 'web')
            ->get();
        $role->syncPermissions($permissions);

        setPermissionsTeamId($tenant->id);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
