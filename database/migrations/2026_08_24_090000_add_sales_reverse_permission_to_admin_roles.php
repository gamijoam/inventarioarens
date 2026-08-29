<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'sales.reverse';

    private const GRANTED_ROLES = ['Owner', 'Administrador'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', self::GRANTED_ROLES)
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('name', self::GRANTED_ROLES)
            ->where('guard_name', 'web')
            ->pluck('id');

        DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $roleIds)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
