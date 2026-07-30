<?php

namespace App\Modules\LocalSupport\Services;

use App\Modules\Sync\Models\SyncInbox;
use App\Modules\Sync\Models\SyncOutbox;
use App\Modules\Sync\Models\SyncState;
use App\Modules\Sync\Services\SyncEventApplier;
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
        $isLoopback = in_array($ip, ['127.0.0.1', '::1'], true);

        abort_unless(
            $enabled && $isLoopback,
            404,
            'La consola tecnica solo esta disponible desde una instalacion local.',
        );
    }

    public function status(): array
    {
        $settings = $this->readSettings();
        $configured = is_array($settings['tenants'] ?? null) ? $settings['tenants'] : [];
        $localTenants = Tenant::withoutGlobalScopes()
            ->orderBy('name')
            ->get()
            ->keyBy('slug');
        $slugs = array_unique(array_merge(array_keys($configured), $localTenants->keys()->all()));

        return [
            'storage_path' => storage_path(),
            'database_path' => (string) config('database.connections.sqlite.database'),
            'cloud_url' => (string) config('services.local_support.cloud_url'),
            'printer' => $this->printerStatus(),
            'tenants' => collect($slugs)
                ->map(function (string $slug) use ($configured, $localTenants): array {
                    /** @var Tenant|null $tenant */
                    $tenant = $localTenants->get($slug);
                    $configuration = is_array($configured[$slug] ?? null) ? $configured[$slug] : [];
                    $state = $tenant instanceof Tenant
                        ? SyncState::withoutGlobalScopes()
                            ->where('tenant_id', $tenant->id)
                            ->orderByDesc('last_attempt_at')
                            ->first()
                        : null;

                    return [
                        'id' => $tenant?->id,
                        'name' => $tenant?->name ?? (string) ($configuration['tenant_name'] ?? $slug),
                        'slug' => $slug,
                        'configured' => $configuration !== [],
                        'ready' => $tenant instanceof Tenant,
                        'node_name' => $configuration['node_name'] ?? null,
                        'node_code' => $configuration['node_code'] ?? null,
                        'interval' => $configuration['interval'] ?? null,
                        'worker' => $this->workerStatus($slug),
                        'last_success_at' => $state?->last_success_at?->toISOString(),
                        'last_attempt_at' => $state?->last_attempt_at?->toISOString(),
                        'last_error' => $state?->last_error,
                        'sync' => $this->syncMetrics($tenant?->id),
                    ];
                })
                ->sortBy('name')
                ->values()
                ->all(),
        ];
    }

    public function connect(array $data): array
    {
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
        $this->writeTenantSettings($slug, $name, $token, $nodeCode, $nodeName, (int) $data['interval']);

        $this->runArtisan('migrate', ['--force' => true]);
        $this->withEnvironment('SYNC_BOOTSTRAP_PASSWORD', (string) $data['local_password'], function () use ($slug, $name, $data): void {
            $this->runArtisan('sync:prepare-local', [
                'tenant_slug' => $slug,
                'tenant_name' => $name,
                'email' => (string) $data['local_email'],
                '--user-name' => (string) ($data['local_user_name'] ?? $data['local_email']),
            ]);
        });

        $worker = $this->workerAction($slug, 'install');

        return [
            'tenant' => ['name' => $name, 'slug' => $slug],
            'download' => [
                'status' => 'started',
                'message' => 'La descarga inicial se esta ejecutando en segundo plano. Puedes seguir su estado en la tarjeta de la empresa.',
            ],
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

    public function retryFailed(string $tenantSlug): array
    {
        $tenant = $this->configuredTenant($tenantSlug);
        $reset = SyncInbox::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'failed')
            ->update([
                'status' => 'received',
                'last_error' => null,
                'updated_at' => now(),
            ]);

        $summary = app(SyncEventApplier::class)->applyPending($tenant, 100);
        $this->runArtisan('images:download', ['--tenant' => $tenantSlug, '--limit' => 100], false);

        return [
            'reset' => $reset,
            'applied' => $summary['applied'],
            'ignored' => $summary['ignored'],
            'failed' => $summary['failed'],
            'sync' => $this->syncMetrics($tenant->id),
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

        $workerScript = base_path('scripts/sync-worker.cmd');
        if (! is_file($workerScript)) {
            throw ValidationException::withMessages([
                'worker' => 'No se encontro el controlador de worker de esta instalacion.',
            ]);
        }

        $output = [];
        if (in_array($action, ['stop', 'restart'], true)) {
            $output[] = $this->stopWindowsWorker($tenantSlug);
        }

        if (in_array($action, ['install', 'start', 'restart'], true)) {
            $output[] = $this->installWindowsWorkerTask($tenantSlug, $workerScript);
            $output[] = $this->runWindowsWorkerTask($tenantSlug);
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

    private function writeTenantSettings(string $slug, string $tenantName, string $token, string $nodeCode, string $nodeName, int $interval): void
    {
        $settings = $this->readSettings();
        $tenants = is_array($settings['tenants'] ?? null) ? $settings['tenants'] : [];
        $installationCode = (string) ($settings['installation_code'] ?? Str::upper(Str::random(12)));
        $cloudUrl = rtrim((string) config('services.local_support.cloud_url'), '/');

        $tenants[$slug] = [
            'tenant_name' => $tenantName,
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

    private function configuredTenant(string $tenantSlug): Tenant
    {
        $this->ensureConfiguredTenant($tenantSlug);

        return Tenant::withoutGlobalScopes()->where('slug', Str::slug($tenantSlug))->firstOrFail();
    }

    private function syncMetrics(?int $tenantId): array
    {
        if ($tenantId === null) {
            return [
                'outbox_pending' => 0,
                'outbox_failed' => 0,
                'inbox_received' => 0,
                'inbox_failed' => 0,
                'inbox_applied' => 0,
            ];
        }

        $inbox = SyncInbox::withoutGlobalScopes()->where('tenant_id', $tenantId);
        $outbox = SyncOutbox::withoutGlobalScopes()->where('tenant_id', $tenantId);

        return [
            'outbox_pending' => (clone $outbox)->whereIn('status', ['pending', 'processing'])->count(),
            'outbox_failed' => (clone $outbox)->where('status', 'failed')->count(),
            'inbox_received' => (clone $inbox)->where('status', 'received')->count(),
            'inbox_failed' => (clone $inbox)->where('status', 'failed')->count(),
            'inbox_applied' => (clone $inbox)->whereIn('status', ['applied', 'processed'])->count(),
        ];
    }

    private function printerStatus(): array
    {
        try {
            $response = Http::acceptJson()->timeout(2)->get('http://127.0.0.1:17777/health');

            return [
                'available' => $response->successful(),
                'message' => $response->successful() ? 'Agente de impresion disponible.' : 'El agente respondio con un error.',
                'url' => 'http://127.0.0.1:17777',
            ];
        } catch (\Throwable) {
            return [
                'available' => false,
                'message' => 'Agente de impresion detenido o no instalado.',
                'url' => 'http://127.0.0.1:17777',
            ];
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
        $window = $this->workerHealthWindow($tenantSlug);
        $active = $pid > 0 && $this->isWindowsProcessActive($pid);
        $recentPid = $pid > 0 && is_file($pidPath) && (int) filemtime($pidPath) >= now()->subSeconds($window)->getTimestamp();
        $recentCycle = $this->hasRecentWorkerCycle($tenantSlug);

        return [
            'available' => true,
            'active' => $active || $recentPid || $recentCycle,
            'pid' => ($active || $recentPid) ? $pid : null,
            'message' => $active ? 'Worker activo.' : (($recentPid || $recentCycle) ? 'Worker activo: ciclo reciente confirmado.' : 'Worker detenido.'),
        ];
    }

    private function hasRecentWorkerCycle(string $tenantSlug): bool
    {
        $tenantId = Tenant::withoutGlobalScopes()->where('slug', $tenantSlug)->value('id');
        if (! $tenantId) {
            return false;
        }

        $attempt = SyncState::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->value('last_attempt_at');
        if ($attempt === null) {
            return false;
        }

        return now()->diffInSeconds($attempt, false) >= -$this->workerHealthWindow($tenantSlug);
    }

    private function workerHealthWindow(string $tenantSlug): int
    {
        $configuration = $this->readSettings()['tenants'][$tenantSlug] ?? [];
        $interval = is_array($configuration) ? (int) ($configuration['interval'] ?? 15) : 15;

        // A first sync can take more than one polling interval while it applies a snapshot.
        return max(360, $interval * 12);
    }

    private function isWindowsProcessActive(int $pid): bool
    {
        $process = new Process([$this->windowsExecutable('tasklist.exe'), '/FI', 'PID eq '.$pid, '/FO', 'CSV', '/NH']);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful() && str_contains($process->getOutput(), '"'.$pid.'"');
    }

    private function installWindowsWorkerTask(string $tenantSlug, string $workerScript): string
    {
        $safeSlug = preg_replace('/[^a-z0-9_-]/', '-', Str::lower($tenantSlug));
        $stateDirectory = storage_path('app/sync-worker');
        $launcher = $stateDirectory.'/sync-task-'.$safeSlug.'.cmd';
        $hiddenRunner = base_path('scripts/run-sync-hidden.vbs');
        File::ensureDirectoryExists($stateDirectory);
        File::put($launcher, sprintf(
            "@echo off\r\ncd /d \"%s\"\r\ncall \"%s\" start -TenantSlug \"%s\"\r\n",
            base_path(),
            $workerScript,
            $tenantSlug,
        ));

        if (! is_file($hiddenRunner)) {
            throw ValidationException::withMessages([
                'worker' => 'Falta el lanzador oculto requerido para iniciar el worker.',
            ]);
        }

        $taskName = 'SistemaInventarioSync-'.$safeSlug;
        $taskCommand = sprintf('"%s" "%s" "%s"', $this->windowsExecutable('wscript.exe'), $hiddenRunner, $launcher);
        $process = new Process([
            $this->windowsExecutable('schtasks.exe'),
            '/Create',
            '/TN', $taskName,
            '/TR', $taskCommand,
            '/SC', 'MINUTE',
            '/MO', '5',
            '/F',
        ]);
        $process->setTimeout(15);
        $process->run();

        if ($process->isSuccessful()) {
            return 'Inicio automatico instalado para '.$tenantSlug.'.';
        }

        throw ValidationException::withMessages([
            'worker' => trim($process->getOutput().' '.$process->getErrorOutput()) ?: 'No se pudo registrar el inicio automatico.',
        ]);
    }

    private function runWindowsWorkerTask(string $tenantSlug): string
    {
        $process = new Process([
            $this->windowsExecutable('schtasks.exe'),
            '/Run',
            '/TN', 'SistemaInventarioSync-'.preg_replace('/[^a-z0-9_-]/', '-', Str::lower($tenantSlug)),
        ]);
        $process->setTimeout(15);
        $process->run();
        $output = trim($process->getOutput().' '.$process->getErrorOutput());

        if (! $process->isSuccessful()) {
            throw ValidationException::withMessages([
                'worker' => $output !== '' ? $output : 'No se pudo iniciar la tarea del worker.',
            ]);
        }

        return 'Worker iniciado mediante la tarea de Windows.';
    }

    private function stopWindowsWorker(string $tenantSlug): string
    {
        $safeSlug = preg_replace('/[^a-z0-9_-]/', '-', Str::lower($tenantSlug));
        $pidPath = storage_path('app/sync-worker/sync-worker-'.$safeSlug.'.pid');
        $pid = is_file($pidPath) ? (int) trim((string) file_get_contents($pidPath)) : 0;

        if ($pid <= 0) {
            return 'No habia un worker activo.';
        }

        $process = new Process([$this->windowsExecutable('taskkill.exe'), '/PID', (string) $pid, '/T', '/F']);
        $process->setTimeout(10);
        $process->run();
        File::delete($pidPath);

        return $process->isSuccessful() ? 'Worker detenido.' : 'El worker ya no estaba activo.';
    }

    private function windowsExecutable(string $name): string
    {
        $windowsRoot = (string) (getenv('SystemRoot') ?: getenv('WINDIR') ?: 'C:\\Windows');
        $path = rtrim($windowsRoot, '\\/').'/System32/'.$name;

        return is_file($path) ? $path : $name;
    }
}
