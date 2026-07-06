<?php

namespace App\Modules\Sync\Commands;

use App\Models\User;
use App\Modules\Auth\Models\AuthToken;
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
        $plainToken = Str::random(80);

        AuthToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => (string) $this->option('name'),
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => ['*'],
            'expires_at' => now()->addDays($days),
            'ip_address' => 'cli',
            'user_agent' => 'sync:issue-token',
        ]);

        $this->info('Token de sincronizacion emitido.');
        $this->line('Empresa: '.$tenant->slug);
        $this->line('Usuario: '.$user->email);
        $this->line('Vence en dias: '.$days);
        $this->warn('Copia este token ahora. No se volvera a mostrar.');
        $this->line($plainToken);

        return self::SUCCESS;
    }
}
