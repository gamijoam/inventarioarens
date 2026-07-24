<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Servicio para que una empresa normal (root sin hijos) se promueva
 * a grupo multi-empresa.
 *
 * Precondiciones:
 *   - El tenant actual NO debe ser un grupo todavia (no es_group).
 *   - El tenant actual NO debe tener padre (parent_id IS NULL) ni hijos
 *     (`children_count = 0`). Esto evita "robar" un tenant que ya forma
 *     parte de otro grupo.
 *   - El user autenticado debe ser miembro activo del tenant.
 *
 * Efectos:
 *   - Marca `is_group = true` y `parent_id = NULL` (lo deja como grupo raiz).
 *   - Crea el rol "Owner" del grupo y se lo asigna al actor.
 *   - Invalida el cache de permisos de Spatie.
 *
 * NO migra catalogos ni stock: el tenant actual pasa a ser el grupo raiz
 * que comparte catalogo con sus futuros spinoffs. El stock local no se toca.
 */
class TenantPromotionService
{
    /**
     * Promueve un tenant a grupo. Devuelve el tenant actualizado.
     */
    public function promote(Tenant $tenant, User $actor): Tenant
    {
        if (! $actor->belongsToTenant($tenant)) {
            throw ValidationException::withMessages([
                'tenant' => 'El usuario no pertenece a la empresa que intenta promover.',
            ]);
        }

        if ($tenant->isGroup()) {
            throw ValidationException::withMessages([
                'tenant' => 'La empresa ya es un grupo.',
            ]);
        }

        if ($tenant->parent_id !== null) {
            throw ValidationException::withMessages([
                'tenant' => 'La empresa ya pertenece a otro grupo. No se puede promover.',
            ]);
        }

        $childrenCount = Tenant::query()->where('parent_id', $tenant->id)->count();
        if ($childrenCount > 0) {
            throw ValidationException::withMessages([
                'tenant' => 'La empresa ya tiene empresas hijas. No se puede promover.',
            ]);
        }

        return DB::transaction(function () use ($tenant, $actor): Tenant {
            $tenant->update([
                'is_group' => true,
                'parent_id' => null,
            ]);

            $this->seedOwnerRole($tenant);
            $this->assignOwnerRole($tenant, $actor);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $tenant->refresh();
        });
    }

    private function seedOwnerRole(Tenant $group): Role
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        $role = Role::query()
            ->where('name', 'Owner')
            ->where($teamColumn, $group->id)
            ->first();

        if (! $role) {
            $role = Role::create([
                'name' => 'Owner',
                'guard_name' => 'web',
                $teamColumn => $group->id,
            ]);
        }

        // Permisos: replicamos el set del rol Administrador ya sembrado en la empresa,
        // asi el Owner hereda todo lo que el admin ya podia hacer.
        $permissions = Permission::query()
            ->whereIn('name', BasePermissions::PERMISSIONS)
            ->where('guard_name', 'web')
            ->get();

        $role->syncPermissions($permissions);

        return $role;
    }

    private function assignOwnerRole(Tenant $group, User $actor): void
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');
        $role = Role::query()
            ->where('name', 'Owner')
            ->where($teamColumn, $group->id)
            ->firstOrFail();

        setPermissionsTeamId($group->id);
        $actor->assignRole($role);
    }
}
