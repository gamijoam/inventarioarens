<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION = 'inventory_transfer_requests.offer';

    private const GRANTED_ROLES = [
        'Owner',
        'Administrador',
        'Gerente',
        'Almacen',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['updated_at' => $now, 'created_at' => $now]
        );

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

        $this->clearPermissionCache();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', function ($query): void {
                    $query->select('id')
                        ->from('roles')
                        ->whereIn('name', self::GRANTED_ROLES)
                        ->where('guard_name', 'web');
                })
                ->delete();
        }

        $this->clearPermissionCache();
    }

    private function clearPermissionCache(): void
    {
        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
