<?php

namespace App\Modules\Sync\Commands;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Permissions\BasePermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PrepareLocalTenantCommand extends Command
{
    protected $signature = 'sync:prepare-local
        {tenant_slug : Slug de la empresa que se preparara en esta instalacion}
        {tenant_name : Nombre visible de la empresa}
        {email : Correo del usuario autorizado}
        {--user-name= : Nombre visible del usuario}
        {--domain= : Dominio opcional de la empresa}
        {--password-env=SYNC_BOOTSTRAP_PASSWORD : Variable de entorno que contiene la clave local}
        {--role=Administrador local : Rol local que se asignara al usuario}';

    protected $description = 'Prepara una empresa y usuario local para poder ejecutar la primera sincronizacion.';

    public function handle(): int
    {
        $tenantSlug = Str::slug((string) $this->argument('tenant_slug'));
        $tenantName = trim((string) $this->argument('tenant_name'));
        $email = Str::lower(trim((string) $this->argument('email')));
        $userName = trim((string) ($this->option('user-name') ?: $email));
        $domain = trim((string) $this->option('domain'));
        $roleName = trim((string) $this->option('role')) ?: 'Administrador local';
        $password = (string) getenv((string) $this->option('password-env'));

        if ($tenantSlug === '' || $tenantName === '' || $email === '') {
            $this->error('Empresa, nombre y correo son obligatorios.');

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('No se recibio la clave local. Define la variable indicada en --password-env.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($tenantSlug, $tenantName, $email, $userName, $domain, $password, $roleName): array {
            $tenant = Tenant::query()->updateOrCreate(
                ['slug' => $tenantSlug],
                [
                    'name' => $tenantName,
                    'domain' => $domain !== '' ? $domain : null,
                    'status' => 'active',
                    'plan' => 'local-sync',
                ],
            );

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $userName !== '' ? $userName : $email,
                    'password' => Hash::make($password),
                ],
            );

            $user->tenants()->syncWithoutDetaching([
                $tenant->id => ['status' => 'active'],
            ]);

            $this->preparePermissions($tenant, $user, $roleName);

            return [$tenant, $user];
        });

        /** @var Tenant $tenant */
        [$tenant, $user] = $result;

        $this->info('Empresa local preparada para sincronizacion.');
        $this->line('Empresa: '.$tenant->slug);
        $this->line('Usuario: '.$user->email);
        $this->line('Rol local: '.$roleName);

        return self::SUCCESS;
    }

    private function preparePermissions(Tenant $tenant, User $user, string $roleName): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BasePermissions::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }

        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions(
            Permission::query()
                ->whereIn('name', BasePermissions::PERMISSIONS)
                ->where('guard_name', 'web')
                ->get(),
        );

        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
