<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'sales_returns.review',
        'sales_returns.process',
        'sales_returns.refund',
        'sales_returns.cancel',
    ];

    private const MANAGER_ROLES = [
        'Owner',
        'Administrador',
        'Gerente',
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

        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'web')
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', self::MANAGER_ROLES)
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

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'web')
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', self::MANAGER_ROLES)
            ->where('guard_name', 'web')
            ->pluck('id');

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_id', $roleIds)
            ->delete();

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
