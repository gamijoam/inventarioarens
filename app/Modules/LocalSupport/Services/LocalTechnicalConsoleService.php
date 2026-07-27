<?php

namespace App\Modules\LocalSupport\Services;

use App\Modules\Sync\Models\SyncState;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class LocalTechnicalConsoleService
{
    public function assertAvailable(string $ip): void
    {
        $enabled = (bool) config('services.local_support.enabled');
        $isLocalEnvironment = app()->environment('local') || app()->runningUnitTests();
        $isLoopback = in_array($ip, ['127.0.0.1', '::1'], true);

        abort_unless(
            $enabled && $isLocalEnvironment && $isLoopback,
            404,
            'La consola tecnica solo esta disponible desde una instalacion local.',
        );
    }

    public function status(): array
    {
        $settings = $this->readSettings();
        $configured = $settings['tenants'] ?? [];

        return [
            'storage_path' => storage_path(),
            'database_path' => (string) config('database.connections.sqlite.database'),
            'cloud_url' => (string) config('services.local_support.cloud_url'),
            'tenants' => Tenant::withoutGlobalScopes()
                ->orderBy('name')
                ->get()
                ->map(function (Tenant $tenant) use ($configured): array {
                    $configuration = is_array($configured[$tenant->slug] ?? null) ? $configured[$tenant->slug] : [];
                    $state = SyncState::withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)
                        ->orderByDesc('last_attempt_at')
                        ->first();

                    return [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                        'configured' => $configuration !== [],
                        'node_name' => $configuration['node_name'] ?? null,
                        'node_code' => $configuration['node_code'] ?? null,
                        'interval' => $configuration['interval'] ?? null,
                        'worker' => $this->workerStatus($tenant->slug),
                        'last_success_at' => $state?->last_success_at?->toISOString(),
                        'last_attempt_at' => $state?->last_attempt_at?->toISOString(),
                        'last_error' => $state?->last_error,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    public function connect(array $data): array
    {
        set_time_limit(180);

        $response = $this->redeemPairingCode($data);
        $tenant = $response['tenant'] ?? [];
        $slug = Str::slug((string) ($tenant['slug'] ?? ''));
        $name = trim((string) ($tenant['name'] ?? ''));
        $token = (string) ($response['token'] ?? '');

        if ($slug === '' || $name === '' || $token === '') {
            throw ValidationException::withMessages([
                'code' => 'El codigo fue aceptado, pero la nube no devolvio los datos completos de la empresa.',
            ]);
        }

        $nodeCode = Str::upper(Str::slug((string) $data['node_code'], '-'));
        $nodeName = trim((string) $data['node_name']);
        $this->writeTenantSettings($slug, $token, $nodeCode, $nodeName, (int) $data['interval']);

        $this->runArtisan('migrate', ['--force' => true]);
        $this->withEnvironment('SYNC_BOOTSTRAP_PASSWORD', (string) $data['local_password'], function () use ($slug, $name, $data): void {
            $this->runArtisan('sync:prepare-local', [
                'tenant_slug' => $slug,
                'tenant_name' => $name,
                'email' => (string) $data['local_email'],
                '--user-name' => (string) ($data['local_user_name'] ?: $data['local_email']),
            ]);
        });

        $sync = $this->syncNow($slug, 3);
        $worker = $this->workerAction($slug, 'install');

        return [
            'tenant' => ['name' => $name, 'slug' => $slug],
            'sync' => $sync,
            'worker' => $worker,
        ];
    }

    public function syncNow(string $tenantSlug, int $cycles = 1): array
    {
        $this->ensureConfiguredTenant($tenantSlug);
        set_time_limit(180);

        $output = [];
        $cycles = max(1, min(5, $cycles));
        for ($cycle = 1; $cycle <= $cycles; $cycle++) {
            $output[] = $this->runArtisan('sync:run', [
                'tenant' => $tenantSlug,
                '--limit' => 100,
            ], false);
            $output[] = $this->runArtisan('sync:apply-inbox', [
                'tenant' => $tenantSlug,
                '--limit' => 100,
            ], false);
        }
        $output[] = $this->runArtisan('images:download', [
            '--tenant' => $tenantSlug,
            '--limit' => 100,
        ], false);

        return [
            'cycles' => $cycles,
            'output' => implode("\n", array_filter($output)),
            'worker' => $this->workerStatus($tenantSlug),
        ];
    }

    public function workerAction(string $tenantSlug, string $action): array
    {
        $this->ensureConfiguredTenant($tenantSlug);

        if (PHP_OS_FAMILY !== 'Windows') {
            throw ValidationException::withMessages([
                'worker' => 'El control grafico de tareas esta disponible en Windows. En Linux usa systemd.',
            ]);
        }

        $script = base_path('scripts/sync-worker-task.ps1');
        if (! is_file($script)) {
            throw ValidationException::withMessages([
                'worker' => 'No se encontro el controlador de worker de esta instalacion.',
            ]);
        }

        $commands = match ($action) {
            'install' => [['install']],
            'start' => [['start']],
            'stop' => [['stop']],
            'restart' => [['stop'], ['start']],
            default => throw ValidationException::withMessages(['worker' => 'Accion de worker invalida.']),
        };

        $output = [];
        foreach ($commands as [$command]) {
            $process = new Process([
                'powershell.exe',
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $script,
                $command,
                '-TenantSlug',
                $tenantSlug,
            ]);
            $process->setTimeout(30);
            $process->run();
            $output[] = trim($process->getOutput().' '.$process->getErrorOutput());

            if (! $process->isSuccessful()) {
                throw ValidationException::withMessages([
                    'worker' => trim(implode("\n", $output)) ?: 'No se pudo actualizar el worker.',
                ]);
            }
        }

        return [
            'output' => trim(implode("\n", $output)),
            'status' => $this->workerStatus($tenantSlug),
        ];
    }

    private function redeemPairingCode(array $data): array
    {
        $cloudUrl = rtrim((string) config('services.local_support.cloud_url'), '/');
        if ($cloudUrl === '') {
            throw ValidationException::withMessages([
                'code' => 'La URL de la nube no esta configurada en esta instalacion.',
            ]);
        }

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->post($cloudUrl.'/sync/pairing-codes/redeem', [
                    'code' => $data['code'],
                    'node_code' => $data['node_code'],
                    'node_name' => $data['node_name'],
                ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'code' => 'No fue posible conectar con la nube. Verifica Internet e intenta de nuevo.',
            ]);
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('message') ?: 'El codigo no es valido, expiro o ya fue utilizado.');
            throw ValidationException::withMessages(['code' => $message]);
        }

        return (array) $response->json('data');
    }

    private function writeTenantSettings(string $slug, string $token, string $nodeCode, string $nodeName, int $interval): void
    {
        $settings = $this->readSettings();
        $tenants = is_array($settings['tenants'] ?? null) ? $settings['tenants'] : [];
        $installationCode = (string) ($settings['installation_code'] ?? Str::upper(Str::random(12)));
        $cloudUrl = rtrim((string) config('services.local_support.cloud_url'), '/');

        $tenants[$slug] = [
            'cloud_url' => $cloudUrl,
            'token' => $token,
            'node_code' => $nodeCode,
            'node_name' => $nodeName,
            'installation_code' => $installationCode,
            'interval' => max(5, min(300, $interval)),
            'limit' => 100,
        ];

        $path = $this->settingsPath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'version' => 2,
            'installation_code' => $installationCode,
            'cloud_url' => $cloudUrl,
            'tenants' => $tenants,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, true);
    }

    private function readSettings(): array
    {
        $path = $this->settingsPath();
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function settingsPath(): string
    {
        return storage_path('app/sync-worker/sync-config.json');
    }

    private function ensureConfiguredTenant(string $tenantSlug): void
    {
        $slug = Str::slug($tenantSlug);
        $settings = $this->readSettings();
        abort_unless(Tenant::withoutGlobalScopes()->where('slug', $slug)->exists(), 404, 'La empresa local no existe.');

        if (! isset($settings['tenants'][$slug]['token'])) {
            throw ValidationException::withMessages([
                'tenant' => 'Esta empresa no tiene una vinculacion de sincronizacion configurada.',
            ]);
        }
    }

    private function runArtisan(string $command, array $parameters = [], bool $throwOnFailure = true): string
    {
        $exitCode = Artisan::call($command, $parameters);
        $output = trim(Artisan::output());

        if ($throwOnFailure && $exitCode !== 0) {
            throw ValidationException::withMessages([
                'operation' => $output !== '' ? $output : 'La operacion local no pudo completarse.',
            ]);
        }

        return $output;
    }

    private function withEnvironment(string $key, string $value, callable $callback): void
    {
        $previous = getenv($key);
        putenv($key.'='.$value);
        try {
            $callback();
        } finally {
            $previous === false ? putenv($key) : putenv($key.'='.$previous);
        }
    }

    private function workerStatus(string $tenantSlug): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return ['available' => false, 'active' => false, 'message' => 'Controlado por systemd en Linux.'];
        }

        $safeSlug = preg_replace('/[^a-z0-9_-]/', '-', Str::lower($tenantSlug));
        $pidPath = storage_path('app/sync-worker/sync-worker-'.$safeSlug.'.pid');
        $pid = is_file($pidPath) ? (int) trim((string) file_get_contents($pidPath)) : 0;
        $active = $pid > 0 && $this->isWindowsProcessActive($pid);

        return [
            'available' => true,
            'active' => $active,
            'pid' => $active ? $pid : null,
            'message' => $active ? 'Worker activo.' : 'Worker detenido.',
        ];
    }

    private function isWindowsProcessActive(int $pid): bool
    {
        $process = new Process(['tasklist.exe', '/FI', 'PID eq '.$pid, '/FO', 'CSV', '/NH']);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful() && str_contains($process->getOutput(), '"'.$pid.'"');
    }
}
