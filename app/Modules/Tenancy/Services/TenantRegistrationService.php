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
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Crea una empresa completa desde cero:
     *   1. Tenant (name, slug, domain?, status, plan)
     *   2. Branch inicial (opcional)
     *   3. Warehouse inicial atado a la branch (opcional)
     *   4. ExchangeRateType BCV inicial (opcional)
     *   5. Usuario administrador (siempre): se crea si no existe, se attach al tenant,
     *      se le asigna el rol 'Administrador' con permisos completos via teams=tenant_id.
     *
     * Todo en DB::transaction. Si cualquier paso falla, rollback completo.
     * Idempotente: si el slug ya existe, retorna el tenant existente sin modificarlo.
     */
    public function register(array $data, User $actor): Tenant
    {
        $slug = Str::slug((string) $data['slug']);

        $existing = Tenant::query()->where('slug', $slug)->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'slug' => "Ya existe una empresa con el slug '{$slug}'.",
            ]);
        }

        return DB::transaction(function () use ($data, $slug, $actor): Tenant {
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