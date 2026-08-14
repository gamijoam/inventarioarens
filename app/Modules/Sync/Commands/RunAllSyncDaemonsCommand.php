<?php

namespace App\Modules\Sync\Commands;

use App\Modules\Sync\Services\SyncWorkerService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

class RunAllSyncDaemonsCommand extends Command
{
    protected $signature = 'sync:daemon-all
        {--interval=15 : Segundos entre ciclos globales}
        {--cycles=0 : Cantidad maxima de ciclos; 0 significa continuo}
        {--once : Ejecuta un solo ciclo y termina}';

    protected $description = 'Supervisa en un solo proceso la sincronizacion de todas las empresas locales configuradas.';

    public function handle(SyncWorkerService $worker): int
    {
        $maximumCycles = $this->option('once') ? 1 : max(0, (int) $this->option('cycles'));
        $interval = max(5, (int) $this->option('interval'));
        $cycle = 0;
        $hadFailures = false;

        $this->info('Supervisor de sincronizacion iniciado.');

        while (true) {
            $cycle++;

            foreach ($this->configuredTenants() as $slug => $configuration) {
                $tenant = Tenant::query()->where('slug', $slug)->first();
                if (! $tenant) {
                    $this->warn($slug.': omitida porque no existe en la base local.');

                    continue;
                }

                try {
                    $summary = $worker->run(
                        tenant: $tenant,
                        nodeCode: (string) ($configuration['node_code'] ?? 'LOCAL-01'),
                        nodeName: (string) ($configuration['node_name'] ?? $configuration['node_code'] ?? 'LOCAL-01'),
                        cloudUrl: (string) $configuration['cloud_url'],
                        token: (string) $configuration['token'],
                        limit: max(1, min(200, (int) ($configuration['limit'] ?? 50))),
                        installationCode: (string) ($configuration['installation_code'] ?? $configuration['node_code'] ?? 'LOCAL-01'),
                    );

                    $this->line(sprintf(
                        '%s: OK (subidos %d, bajados %d, aplicados %d, fallos %d)',
                        $slug,
                        $summary['pushed'],
                        $summary['pulled'],
                        $summary['applied'],
                        $summary['failed'],
                    ));
                    $hadFailures = $hadFailures || $summary['failed'] > 0;
                } catch (Throwable $exception) {
                    $hadFailures = true;
                    $this->error($slug.': ERROR - '.$exception->getMessage());
                }
            }

            if ($maximumCycles > 0 && $cycle >= $maximumCycles) {
                $this->info('Supervisor de sincronizacion detenido por limite de ciclos.');

                return $hadFailures ? self::FAILURE : self::SUCCESS;
            }

            sleep($interval);
        }
    }

    private function configuredTenants(): array
    {
        $path = storage_path('app/sync-worker/sync-config.json');
        if (! is_file($path)) {
            return [];
        }

        $settings = json_decode((string) file_get_contents($path), true);
        $tenants = is_array($settings['tenants'] ?? null) ? $settings['tenants'] : [];

        return array_filter(
            $tenants,
            static fn (mixed $configuration): bool => is_array($configuration)
                && ! empty($configuration['token'])
                && ! empty($configuration['cloud_url']),
        );
    }
}
