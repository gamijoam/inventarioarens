<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'reports.sales.view',
        'reports.cash.view',
        'reports.inventory.view',
        'reports.movements.view',
        'reports.export',
        'finance_reports.export',
    ];

    private const MANAGER_ROLES = [
        'Owner',
        'Administrador',
        'Gerente',
    ];

    private const VIEW_ROLES = [
        'Auditor',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['updated_at' => $now, 'created_at' => $now]
            );
        }

        $this->grant(self::MANAGER_ROLES, self::PERMISSIONS);
        $this->grant(self::VIEW_ROLES, [
            'reports.sales.view',
            'reports.cash.view',
            'reports.inventory.view',
            'reports.movements.view',
        ]);

        $this->clearPermissionCache();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'web')
            ->pluck('id');

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        $this->clearPermissionCache();
    }

    private function grant(array $roles, array $permissions): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', $permissions)
            ->where('guard_name', 'web')
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', $roles)
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    private function clearPermissionCache(): void
    {
        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
