<?php

namespace App\Modules\Sync\Commands;

use App\Modules\Sync\Services\SyncWorkerService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

class RunSyncDaemonCommand extends Command
{
    protected $signature = 'sync:daemon
        {tenant : Slug de la empresa local}
        {--node=LOCAL-01 : Codigo unico del nodo local}
        {--name= : Nombre visible del nodo local}
        {--installation= : Codigo estable de la instalacion local}
        {--cloud-url= : URL base del API en la nube, por ejemplo https://dominio.com/api}
        {--token= : Token Bearer del API en la nube}
        {--limit=50 : Cantidad maxima de eventos por ciclo}
        {--interval=30 : Segundos entre ciclos}
        {--cycles=0 : Cantidad maxima de ciclos. 0 significa continuo}
        {--once : Ejecuta un solo ciclo y termina}
        {--push-only : Solo sube eventos locales}
        {--pull-only : Solo baja eventos desde la nube}
        {--no-apply : Recibe eventos pero no los aplica automaticamente}';

    protected $description = 'Mantiene la sincronizacion local-nube corriendo por ciclos.';

    public function handle(SyncWorkerService $worker): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();

        if (! $tenant) {
            $this->error('No se encontro la empresa indicada.');

            return self::FAILURE;
        }

        // Auto-fill token/cloud_url/interval from per-tenant config (WPF installer writes
        // storage/app/sync-worker/sync-config.json). The previous implementation used
        // only the global SYNC_CLOUD_TOKEN, which failed for any tenant whose token
        // was different from the .env one.
        $perTenant = $this->loadPerTenantConfig((string) $tenant->slug);

        $cloudUrl = (string) ($this->option('cloud-url') ?: ($perTenant['cloud_url'] ?? null) ?: config('services.sync.cloud_url'));
        $token = (string) ($this->option('token') ?: ($perTenant['token'] ?? null) ?: config('services.sync.token'));

        if (! $cloudUrl || ! $token) {
            $this->error('Debes indicar --cloud-url y --token, o configurar SYNC_CLOUD_URL y SYNC_CLOUD_TOKEN, o instalar la empresa con el configurador WPF para escribir storage/app/sync-worker/sync-config.json.');

            return self::FAILURE;
        }

        $nodeCode = (string) ($this->option('node') ?: ($perTenant['node_code'] ?? null) ?: 'LOCAL-01');
        $nodeName = (string) ($this->option('name') ?: ($perTenant['node_name'] ?? null) ?: $nodeCode);
        $installationCode = (string) ($this->option('installation') ?: ($perTenant['installation_code'] ?? null) ?: $nodeCode);
        $limit = max(1, min(200, (int) ($this->option('limit') ?: ($perTenant['limit'] ?? null) ?: 50)));
        $interval = max(5, (int) ($this->option('interval') ?: ($perTenant['interval'] ?? null) ?: 30));
        $maxCycles = $this->option('once') ? 1 : max(0, (int) $this->option('cycles'));
        $push = ! $this->option('pull-only');
        $pull = ! $this->option('push-only');
        $apply = ! $this->option('no-apply');
        $cycle = 0;
        $hadFailures = false;

        $this->info('Worker continuo de sincronizacion iniciado.');
        $this->line('Empresa: '.$tenant->slug);
        $this->line('Nodo: '.$nodeCode);
        $this->line('Intervalo: '.$interval.' segundos');

        while (true) {
            $cycle++;
            $this->line('');
            $this->line('Ciclo '.$cycle.' - '.now()->format('Y-m-d H:i:s'));

            try {
                $summary = $worker->run(
                    tenant: $tenant,
                    nodeCode: $nodeCode,
                    nodeName: $nodeName,
                    cloudUrl: (string) $cloudUrl,
                    token: (string) $token,
                    limit: $limit,
                    push: $push,
                    pull: $pull,
                    apply: $apply,
                    installationCode: $installationCode,
                );

                $this->line(sprintf(
                    'Subidos: %d | Bajados: %d | Aplicados: %d | Ignorados: %d | Fallos: %d',
                    $summary['pushed'],
                    $summary['pulled'],
                    $summary['applied'],
                    $summary['ignored'],
                    $summary['failed'],
                ));

                $hadFailures = $hadFailures || $summary['failed'] > 0;
            } catch (Throwable $exception) {
                $hadFailures = true;
                $this->error('Fallo de sincronizacion: '.$exception->getMessage());
            }

            if ($maxCycles > 0 && $cycle >= $maxCycles) {
                $this->info('Worker continuo detenido por limite de ciclos.');

                return $hadFailures ? self::FAILURE : self::SUCCESS;
            }

            sleep($interval);
        }
    }

    /**
     * Read the per-tenant sync config written by the WPF installer
     * (storage/app/sync-worker/sync-config.json). Returns an empty
     * array if the file does not exist or the tenant is not configured.
     */
    private function loadPerTenantConfig(string $tenantSlug): array
    {
        $path = storage_path('app/sync-worker/sync-config.json');
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['tenants'][$tenantSlug]) || ! is_array($data['tenants'][$tenantSlug])) {
            return [];
        }
        return $data['tenants'][$tenantSlug];
    }
}
