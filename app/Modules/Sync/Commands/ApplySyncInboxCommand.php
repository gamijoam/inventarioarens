<?php

namespace App\Modules\Sync\Commands;

use App\Modules\Sync\Services\SyncEventApplier;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;

class ApplySyncInboxCommand extends Command
{
    protected $signature = 'sync:apply-inbox
        {tenant : Slug de la empresa}
        {--limit=200 : Cantidad maxima de eventos recibidos a aplicar}';

    protected $description = 'Aplica eventos recibidos en sync_inbox que quedaron pendientes en esta base.';

    public function handle(SyncEventApplier $applier, TenantManager $tenancy): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();

        if (! $tenant) {
            $this->error('No se encontro la empresa indicada.');

            return self::FAILURE;
        }

        $tenancy->set($tenant);

        $limit = max(1, min(500, (int) $this->option('limit')));
        $summary = $applier->applyPending($tenant, $limit);

        $this->info('Eventos recibidos procesados.');
        $this->line('Empresa: '.$tenant->slug);
        $this->line('Aplicados: '.$summary['applied']);
        $this->line('Ignorados: '.$summary['ignored']);
        $this->line('Fallidos: '.$summary['failed']);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
