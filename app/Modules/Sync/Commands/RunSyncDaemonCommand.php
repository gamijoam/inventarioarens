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

        $cloudUrl = $this->option('cloud-url') ?: config('services.sync.cloud_url');
        $token = $this->option('token') ?: config('services.sync.token');

        if (! $cloudUrl || ! $token) {
            $this->error('Debes indicar --cloud-url y --token, o configurar SYNC_CLOUD_URL y SYNC_CLOUD_TOKEN.');

            return self::FAILURE;
        }

        $nodeCode = (string) $this->option('node');
        $nodeName = (string) ($this->option('name') ?: $nodeCode);
        $installationCode = (string) ($this->option('installation') ?: $nodeCode);
        $limit = max(1, min(200, (int) $this->option('limit')));
        $interval = max(5, (int) $this->option('interval'));
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
}
