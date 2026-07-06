<?php

namespace Database\Seeders;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Tenant::query()->each(function (Tenant $tenant): void {
            setPermissionsTeamId($tenant->id);

            foreach (BasePermissions::ROLE_PERMISSIONS as $roleName => $permissions) {
                foreach ($permissions as $permission) {
                    Permission::findOrCreate($permission, 'web');
                }

                $role = Role::findOrCreate($roleName, 'web');
                $role->syncPermissions(
                    Permission::query()
                        ->whereIn('name', $permissions)
                        ->where('guard_name', 'web')
                        ->get()
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
