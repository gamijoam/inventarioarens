<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'printing.view',
        'printing.manage',
        'printing.print',
        'printing.reprint',
        'printing.digital',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->grant(['Owner', 'Administrador'], $this->permissions);
        $this->grant(['Gerente'], ['printing.view', 'printing.manage', 'printing.print', 'printing.reprint', 'printing.digital']);
        $this->grant(['Vendedor'], ['printing.view', 'printing.print', 'printing.digital']);
        $this->grant(['Auditor'], ['printing.view']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', fn ($query) => $query->select('id')->from('permissions')->whereIn('name', $this->permissions))
            ->delete();

        DB::table('permissions')->whereIn('name', $this->permissions)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grant(array $roles, array $permissions): void
    {
        Role::query()
            ->whereIn('name', $roles)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));
    }
};
