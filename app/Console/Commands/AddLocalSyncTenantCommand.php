<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class AddLocalSyncTenantCommand extends Command
{
    protected $signature = 'local:add-sync-tenant
        {tenant_slug : Slug de la empresa hija}
        {tenant_name : Nombre visible de la empresa}
        {email : Correo del usuario administrador local}
        {--user-name= : Nombre visible del usuario}
        {--password-env=SYNC_NEW_TENANT_PASSWORD : Variable con la clave local}
        {--token-env=SYNC_NEW_TENANT_TOKEN : Variable con el token de sync emitido por el VPS}
        {--cloud-url= : URL base del API en la nube}
        {--installation= : Codigo estable de la instalacion local}
        {--node-prefix=LOCAL : Prefijo del nodo de sync}
        {--interval=30 : Segundos entre ciclos del worker}
        {--limit=50 : Maximo de eventos por ciclo}';

    protected $description = 'Agrega una empresa local y configura su worker de sincronizacion';

    public function handle(): int
    {
        $passwordEnv = (string) $this->option('password-env');
        $tokenEnv = (string) $this->option('token-env');
        $password = (string) getenv($passwordEnv);
        $token = (string) getenv($tokenEnv);
        $cloudUrl = trim((string) ($this->option('cloud-url') ?: config('services.sync.cloud_url')));

        if ($password === '') {
            $this->error('No se encontro la clave local en '.$passwordEnv.'.');

            return self::FAILURE;
        }

        if ($token === '') {
            $this->error('No se encontro el token de sync en '.$tokenEnv.'.');

            return self::FAILURE;
        }

        if ($cloudUrl === '') {
            $this->error('Debes indicar --cloud-url o configurar SYNC_CLOUD_URL.');

            return self::FAILURE;
        }

        $slug = Str::slug((string) $this->argument('tenant_slug'));
        $name = trim((string) $this->argument('tenant_name'));
        $email = Str::lower(trim((string) $this->argument('email')));

        $prepareExitCode = Artisan::call('sync:prepare-local', [
            'tenant_slug' => $slug,
            'tenant_name' => $name,
            'email' => $email,
            '--user-name' => $this->option('user-name'),
            '--password-env' => $passwordEnv,
        ]);

        if ($prepareExitCode !== self::SUCCESS) {
            return $prepareExitCode;
        }

        $configureExitCode = Artisan::call('local:configure-sync-tenants', [
            '--tenant' => [$slug.'='.$token],
            '--cloud-url' => $cloudUrl,
            '--installation' => $this->option('installation'),
            '--node-prefix' => $this->option('node-prefix'),
            '--interval' => $this->option('interval'),
            '--limit' => $this->option('limit'),
        ]);

        if ($configureExitCode !== self::SUCCESS) {
            return $configureExitCode;
        }

        $this->info('Empresa agregada a la instalacion local: '.$slug);
        $this->line('El worker quedara listo para instalarse con scripts/sync-worker-all.ps1 install.');
        $this->line('El token no se imprime ni se guarda en la salida del comando.');

        return self::SUCCESS;
    }
}
