<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ConfigureLocalSyncTenantsCommand extends Command
{
    protected $signature = 'local:configure-sync-tenants
        {--tenant=* : Empresa y token en formato slug=token. Se puede repetir}
        {--cloud-url= : URL base del API en la nube}
        {--installation= : Codigo estable de esta instalacion local}
        {--node-prefix=LOCAL : Prefijo para los codigos de nodo}
        {--interval=30 : Segundos entre ciclos del worker}
        {--limit=50 : Maximo de eventos por ciclo}
        {--replace : Reemplaza la lista existente en vez de agregarla}';

    protected $description = 'Configura workers de sync independientes para varias empresas locales';

    public function handle(): int
    {
        $entries = $this->option('tenant');
        if ($entries === []) {
            $this->error('Debes indicar al menos un --tenant=slug=token.');

            return self::FAILURE;
        }

        $cloudUrl = trim((string) ($this->option('cloud-url') ?: config('services.sync.cloud_url')));
        if ($cloudUrl === '') {
            $this->error('Debes indicar --cloud-url o configurar SYNC_CLOUD_URL.');

            return self::FAILURE;
        }

        $config = $this->option('replace') ? [] : $this->readConfig();
        $tenants = is_array($config['tenants'] ?? null) ? $config['tenants'] : [];
        $installation = trim((string) ($this->option('installation') ?: ($config['installation_code'] ?? Str::upper(Str::random(12)))));
        $nodePrefix = trim((string) ($this->option('node-prefix') ?: 'LOCAL')) ?: 'LOCAL';
        $interval = max(5, (int) $this->option('interval'));
        $limit = max(1, min(200, (int) $this->option('limit')));

        foreach ($entries as $entry) {
            [$slug, $token] = array_pad(explode('=', (string) $entry, 2), 2, '');
            $slug = Str::slug(trim($slug));
            $token = trim($token);

            if ($slug === '' || $token === '') {
                $this->error('Formato invalido: '.$entry.'. Usa slug=token.');

                return self::FAILURE;
            }

            $safeNodeSlug = Str::upper((string) preg_replace('/[^A-Za-z0-9]+/', '-', $slug));
            $nodeCode = Str::upper($nodePrefix).'-'.$safeNodeSlug;
            $tenants[$slug] = [
                'cloud_url' => $cloudUrl,
                'token' => $token,
                'node_code' => $nodeCode,
                'node_name' => 'Local '.$slug,
                'installation_code' => $installation,
                'interval' => $interval,
                'limit' => $limit,
            ];
        }

        $path = storage_path('app/sync-worker/sync-config.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'version' => 2,
            'installation_code' => $installation,
            'cloud_url' => $cloudUrl,
            'tenants' => $tenants,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->info('Configuracion multiempresa guardada.');
        foreach (array_keys($tenants) as $slug) {
            $this->line('Worker: '.$slug.' ('.$tenants[$slug]['node_code'].')');
        }
        $this->line('Archivo: '.$path);
        $this->line('Cada empresa conserva su propio token y worker.');

        return self::SUCCESS;
    }

    private function readConfig(): array
    {
        $path = storage_path('app/sync-worker/sync-config.json');
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
