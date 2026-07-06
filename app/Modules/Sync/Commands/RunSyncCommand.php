<?php

namespace App\Modules\Sync\Commands;

use App\Modules\Sync\Services\SyncWorkerService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

class RunSyncCommand extends Command
{
    protected $signature = 'sync:run
        {tenant : Slug de la empresa local}
        {--node=LOCAL-01 : Codigo unico del nodo local}
        {--name= : Nombre visible del nodo local}
        {--cloud-url= : URL base del API en la nube, por ejemplo https://dominio.com/api}
        {--token= : Token Bearer del API en la nube}
        {--limit=50 : Cantidad maxima de eventos por ciclo}
        {--push-only : Solo sube eventos locales}
        {--pull-only : Solo baja eventos desde la nube}';

    protected $description = 'Ejecuta un ciclo de sincronizacion local-nube y nube-local.';

    public function handle(SyncWorkerService $worker): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();

        if (! $tenant) {
            $this->error('No se encontro la empresa indicada.');

            return self::FAILURE;
        }

        $cloudUrl = $this->option('cloud-url') ?: config('services.sync.cloud_url');
        $token = $this->option('token') ?: config('services.sync.token');
        $nodeCode = (string) $this->option('node');
        $nodeName = $this->option('name') ?: $nodeCode;
        $limit = max(1, min(200, (int) $this->option('limit')));
        $push = ! $this->option('pull-only');
        $pull = ! $this->option('push-only');

        if (! $cloudUrl || ! $token) {
            $this->error('Debes indicar --cloud-url y --token, o configurar SYNC_CLOUD_URL y SYNC_CLOUD_TOKEN.');

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
        );

        $this->info('Sincronizacion ejecutada.');
        $this->line('Nodo: '.$summary['node_code']);
        $this->line('Eventos subidos: '.$summary['pushed']);
        $this->line('Duplicados en nube: '.$summary['duplicated_on_cloud']);
        $this->line('Eventos bajados: '.$summary['pulled']);
        $this->line('Eventos confirmados: '.$summary['acknowledged']);
        $this->line('Fallos: '.$summary['failed']);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
