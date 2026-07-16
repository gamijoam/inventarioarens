<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Branches\Models\Branch;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TenantGroupService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Crea un grupo (tenant raiz con parent_id=NULL).
     * Asigna el primer Owner (group owner) que administrara todas las empresas del grupo.
     * Tambien crea branch + warehouse + exchange rate BCV si vienen en payload.
     */
    public function createGroup(array $data, User $actor): Tenant
    {
        $slug = Str::slug((string) $data['slug']);

        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'slug' => "Ya existe una empresa con el slug '{$slug}'.",
            ]);
        }

        return DB::transaction(function () use ($data, $slug, $actor): Tenant {
            $tenantManager = app(TenantManager::class);
            $previousTenant = $tenantManager->current();

            try {
                $tenant = Tenant::query()->create([
                    'name' => $data['name'],
                    'slug' => $slug,
                    'domain' => $data['domain'] ?? null,
                    'status' => 'active',
                    'plan' => $data['plan'] ?? 'enterprise',
                    'parent_id' => null,
                    'is_group' => true,
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

                $groupOwner = $this->upsertGroupOwner($tenant, $data['group_owner']);

                $this->audit->record('tenant_group.created', $tenant, $actor, null, [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'group_owner_email' => $groupOwner->email,
                    'branch_code' => $branch?->code,
                    'warehouse_code' => $data['warehouse']['code'] ?? null,
                ]);

                return $tenant->refresh();
            } finally {
                if ($previousTenant) {
                    $tenantManager->set($previousTenant);
                } else {
                    $tenantManager->clear();
                }
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
    }

    public function listGroups(): mixed
    {
        return Tenant::query()
            ->groups()
            ->withCount(['children', 'users'])
            ->orderBy('name')
            ->paginate(25);
    }

    private function upsertGroupOwner(Tenant $tenant, array $ownerData): User
    {
        $email = Str::lower($ownerData['email']);
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->name = $ownerData['name'];
            $user->password = Hash::make($ownerData['password'] ?? Str::random(32));
            $user->save();
        } elseif (! empty($ownerData['name'])) {
            $user->name = $ownerData['name'];
            $user->save();
        }

        if (! empty($ownerData['password']) && strlen($ownerData['password']) >= 8) {
            $user->password = Hash::make($ownerData['password']);
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
            ->where('name', 'Owner')
            ->where($teamColumn, $tenant->id)
            ->first();

        if (! $role) {
            $role = Role::create([
                'name' => 'Owner',
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
}