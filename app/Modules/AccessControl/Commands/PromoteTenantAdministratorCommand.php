<?php

namespace App\Modules\AccessControl\Commands;

use App\Models\User;
use App\Support\Permissions\BasePermissions;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PromoteTenantAdministratorCommand extends Command
{
    protected $signature = 'access:promote-admin
        {email : Correo del usuario que sera administrador}
        {--tenant=* : Slug de empresa especifica. Si se omite, aplica a todas las empresas del usuario}
        {--role=Administrador : Nombre del rol administrador que se asignara}';

    protected $description = 'Asigna permisos administrativos completos a un usuario dentro de sus empresas.';

    public function handle(): int
    {
        $email = Str::lower((string) $this->argument('email'));
        $roleName = trim((string) $this->option('role')) ?: 'Administrador';

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('No se encontro el usuario indicado.');

            return self::FAILURE;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $tenants = $this->tenantsForUser($user);

        if ($tenants->isEmpty()) {
            $this->error('El usuario no pertenece a ninguna empresa para reparar.');

            return self::FAILURE;
        }

        $permissions = Permission::query()
            ->whereIn('name', BasePermissions::PERMISSIONS)
            ->where('guard_name', 'web')
            ->get();

        foreach ($tenants as $tenant) {
            app(TenantManager::class)->set($tenant);
            setPermissionsTeamId($tenant->id);

            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
            $user->assignRole($role);

            $this->line("Empresa reparada: {$tenant->slug} ({$tenant->name})");
        }

        app(TenantManager::class)->clear();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("Usuario {$email} ahora tiene rol {$roleName} con permisos completos.");

        return self::SUCCESS;
    }

    private function tenantsForUser(User $user): mixed
    {
        $tenantSlugs = collect($this->option('tenant'))
            ->filter()
            ->values();

        $query = $user->tenants()->wherePivot('status', 'active');

        if ($tenantSlugs->isNotEmpty()) {
            $query->whereIn('slug', $tenantSlugs);
        }

        return $query->get();
    }
}
