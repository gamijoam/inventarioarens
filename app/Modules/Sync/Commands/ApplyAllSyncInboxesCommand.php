<?php

namespace App\Modules\Sync\Commands;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Itera sobre todos los tenants y aplica los inbox de sync de cada uno.
 *
 * Complementa a `sync:apply-inbox` (que requiere --tenant) para que el
 * systemd timer pueda llamar a un solo comando y procesar todo en
 * bloque. Es un wrapper de conveniencia, no duplica logica.
 */
class ApplyAllSyncInboxesCommand extends Command
{
    protected $signature = 'sync:apply-all-inboxes {--limit=200 : Limite por tenant}';

    protected $description = 'Procesa los inbox de sync de TODOS los tenants activos. Complementa a sync:apply-inbox (que requiere tenant especifico).';

    public function handle(): int
    {
        $tenants = Tenant::query()->where('status', 'active')->get();
        if ($tenants->isEmpty()) {
            $this->info('No hay tenants activos. Nada que hacer.');

            return self::SUCCESS;
        }

        $totalApplied = 0;
        $totalFailed = 0;
        $limit = (int) $this->option('limit');

        foreach ($tenants as $tenant) {
            $this->info("Procesando inbox de tenant: {$tenant->slug} (id={$tenant->id})");
            $exit = $this->call('sync:apply-inbox', [
                'tenant' => $tenant->slug,
                '--limit' => $limit,
            ]);
            if ($exit === self::SUCCESS) {
                $totalApplied++;
            } else {
                $totalFailed++;
            }
        }

        $this->info("Resumen: {$totalApplied} tenants OK, {$totalFailed} con error.");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
