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

class TenantSpinoffService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Crea una empresa spinoff dentro de un grupo existente.
     * La nueva empresa tiene parent_id = $group->id.
     * El admin puede ser un usuario existente del grupo o un usuario nuevo.
     */
    public function createSpinoff(Tenant $group, array $data, User $actor): Tenant
    {
        if (! $group->isGroup()) {
            throw ValidationException::withMessages([
                'group' => "El tenant '{$group->slug}' no es un grupo raiz.",
            ]);
        }

        $slug = Str::slug((string) $data['slug']);

        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => "Ya existe una empresa con el slug '{$slug}'.",
            ]);
        }

        return DB::transaction(function () use ($group, $data, $slug, $actor): Tenant {
            $tenantManager = app(TenantManager::class);
            $previousTenant = $tenantManager->current();

            try {
                $tenant = Tenant::query()->create([
                    'name' => $data['name'],
                    'slug' => $slug,
                    'domain' => $data['domain'] ?? null,
                    'status' => 'active',
                    'plan' => $data['plan'] ?? $group->plan,
                    'parent_id' => $group->id,
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

                $this->seedBaseRoles($tenant);

                $admin = $this->upsertAdmin($tenant, $data['admin']);

                $actor->tenants()->syncWithoutDetaching([
                    $tenant->id => ['status' => 'active'],
                ]);

                $this->assignAdminRole($tenant, $actor);

                $this->audit->record('tenant.spun_off_from_group', $tenant, $actor, null, [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'group_slug' => $group->slug,
                    'group_id' => $group->id,
                    'admin_email' => $admin->email,
                    'branch_code' => $branch?->code,
                    'warehouse_code' => $data['warehouse']['code'] ?? null,
                ]);

                return $tenant->refresh();
            } finally {
                if ($previousTenant) {
                    $tenantManager->set($previousTenant);
                    setPermissionsTeamId($previousTenant->id);
                } else {
                    $tenantManager->clear();
                }
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
    }

    public function listSpinoffs(Tenant $group): mixed
    {
        return Tenant::query()
            ->spinoffs()
            ->where('parent_id', $group->id)
            ->withCount('users')
            ->orderBy('name')
            ->paginate(25);
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

        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        $role = Role::query()
            ->where('name', 'Administrador')
            ->where($teamColumn, $tenant->id)
            ->first();

        if (! $role) {
            $role = Role::create([
                'name' => 'Administrador',
                'guard_name' => 'web',
                $teamColumn => $tenant->id,
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

    public function seedBaseRoles(Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        foreach (BasePermissions::ROLE_PERMISSIONS as $roleName => $permissions) {
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
            }

            $role = Role::query()
                ->where('name', $roleName)
                ->where($teamColumn, $tenant->id)
                ->first();

            if (! $role) {
                $role = Role::create([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    $teamColumn => $tenant->id,
                ]);
            }

            $role->syncPermissions(
                Permission::query()
                    ->whereIn('name', $permissions)
                    ->where('guard_name', 'web')
                    ->get()
            );
        }
    }

    private function assignAdminRole(Tenant $tenant, User $user): void
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        $role = Role::query()
            ->where('name', 'Administrador')
            ->where($teamColumn, $tenant->id)
            ->first();

        if (! $role) {
            return;
        }

        setPermissionsTeamId($tenant->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
