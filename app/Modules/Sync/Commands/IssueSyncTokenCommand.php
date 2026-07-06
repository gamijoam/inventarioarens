<?php

namespace App\Modules\Sync\Commands;

use App\Models\User;
use App\Modules\Sync\Services\SyncTokenService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueSyncTokenCommand extends Command
{
    protected $signature = 'sync:issue-token
        {tenant : Slug de la empresa}
        {email : Correo del usuario que autorizara la sincronizacion}
        {--name=sync-worker : Nombre descriptivo del token}
        {--days=365 : Dias de vigencia del token}';

    protected $description = 'Emite un token Bearer para sincronizacion local-nube de una empresa.';

    public function handle(): int
    {
        $tenant = Tenant::query()
            ->where('slug', $this->argument('tenant'))
            ->first();

        if (! $tenant) {
            $this->error('No se encontro la empresa indicada.');

            return self::FAILURE;
        }

        $user = User::query()
            ->where('email', Str::lower((string) $this->argument('email')))
            ->first();

        if (! $user || ! $user->belongsToTenant($tenant)) {
            $this->error('El usuario no existe o no pertenece a esta empresa.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $session = app(SyncTokenService::class)->issue(
            tenant: $tenant,
            user: $user,
            name: (string) $this->option('name'),
            days: $days,
            ipAddress: 'cli',
            userAgent: 'sync:issue-token',
        );

        $this->info('Token de sincronizacion emitido.');
        $this->line('Empresa: '.$tenant->slug);
        $this->line('Usuario: '.$user->email);
        $this->line('Vence en dias: '.$days);
        $this->warn('Copia este token ahora. No se volvera a mostrar.');
        $this->line($session['token']);

        return self::SUCCESS;
    }
}
