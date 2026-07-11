<?php

namespace App\Modules\Sync\Commands;

use App\Modules\Sync\Services\SyncWorkerService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

class RunSyncCommand extends Command
{
    protected $signature = 'sync:run
        {tenant : Slug de la empresa local}
        {--node= : Codigo unico del nodo local}
        {--name= : Nombre visible del nodo local}
        {--installation= : Codigo estable de la instalacion local}
        {--cloud-url= : URL base del API en la nube, por ejemplo https://dominio.com/api}
        {--token= : Token Bearer del API en la nube}
        {--limit=50 : Cantidad maxima de eventos por ciclo}
        {--push-only : Solo sube eventos locales}
        {--pull-only : Solo baja eventos desde la nube}
        {--no-apply : Recibe eventos pero no los aplica automaticamente}';

    protected $description = 'Ejecuta un ciclo de sincronizacion local-nube y nube-local.';

    public function handle(SyncWorkerService $worker): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();

        if (! $tenant) {
            $this->error('No se encontro la empresa indicada.');

            return self::FAILURE;
        }

        // Auto-fill from per-tenant config (storage/app/sync-worker/sync-config.json) when
        // the WPF installer or a previous sync run wrote a token for this tenant. This makes
        // the worker self-sufficient: the .cmd scripts no longer have to pass --token
        // explicitly, and the global SYNC_CLOUD_TOKEN in .env stops shadowing the per-tenant
        // token (which was the cause of the 403 "Token does not belong to this tenant").
        $perTenant = $this->loadPerTenantConfig((string) $tenant->slug);

        $cloudUrl = (string) ($this->option('cloud-url') ?: ($perTenant['cloud_url'] ?? null) ?: config('services.sync.cloud_url'));
        $token = (string) ($this->option('token') ?: ($perTenant['token'] ?? null) ?: config('services.sync.token'));
        $nodeCode = (string) ($this->option('node') ?: ($perTenant['node_code'] ?? null) ?: 'LOCAL-01');
        $nodeName = (string) ($this->option('name') ?: ($perTenant['node_name'] ?? null) ?: $nodeCode);
        $installationCode = (string) ($this->option('installation') ?: ($perTenant['installation_code'] ?? null) ?: $nodeCode);
        $limit = max(1, min(200, (int) ($this->option('limit') ?: ($perTenant['limit'] ?? null) ?: 50)));
        $push = ! $this->option('pull-only');
        $pull = ! $this->option('push-only');
        $apply = ! $this->option('no-apply');

        if (! $cloudUrl || ! $token) {
            $this->error('Debes indicar --cloud-url y --token, o configurar SYNC_CLOUD_URL y SYNC_CLOUD_TOKEN, o instalar la empresa con el configurador WPF para escribir storage/app/sync-worker/sync-config.json.');

            return self::FAILURE;
        }

        $summary = $worker->run(
            tenant: $tenant,
            nodeCode: $nodeCode,
            nodeName: (string) $nodeName,
            cloudUrl: (string) $cloudUrl,
            token: (string) $token,
            limit: $limit,
            push: $push,
            pull: $pull,
            apply: $apply,
            installationCode: (string) $installationCode,
        );

        $this->info('Sincronizacion ejecutada.');
        $this->line('Nodo: '.$summary['node_code']);
        $this->line('Eventos subidos: '.$summary['pushed']);
        $this->line('Duplicados en nube: '.$summary['duplicated_on_cloud']);
        $this->line('Eventos bajados: '.$summary['pulled']);
        $this->line('Eventos confirmados: '.$summary['acknowledged']);
        $this->line('Eventos aplicados: '.$summary['applied']);
        $this->line('Eventos ignorados: '.$summary['ignored']);
        $this->line('Fallos: '.$summary['failed']);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
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
