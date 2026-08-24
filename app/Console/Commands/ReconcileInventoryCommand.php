<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Services\InventoryReconciliationService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

class ReconcileInventoryCommand extends Command
{
    protected $signature = 'inventory:reconcile
        {tenant? : ID o slug del tenant a reconciliar; por defecto todos}
        {--fix : Corrige los saldos que presenten drift}
        {--fix-serials : Corrige saldos serializados usando los IMEI/seriales existentes}
        {--dry-run : Reporta los cambios sin modificar datos}';

    protected $description = 'Compara saldos contra el ledger y los IMEI/seriales existentes.';

    public function handle(InventoryReconciliationService $service): int
    {
        $tenants = Tenant::query()
            ->when($this->argument('tenant') !== null, function ($query): void {
                $tenant = (string) $this->argument('tenant');
                $query->where(fn ($nested) => $nested
                    ->where('id', (int) $tenant)
                    ->orWhere('slug', $tenant));
            })
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('No se encontro el tenant indicado.');

            return self::FAILURE;
        }

        $totalDrifts = 0;
        $totalSerialDrifts = 0;
        $totalFixed = 0;
        $fix = (bool) $this->option('fix');
        $fixSerials = (bool) $this->option('fix-serials');
        $dryRun = (bool) $this->option('dry-run');

        foreach ($tenants as $tenant) {
            $result = $service->reconcileTenant($tenant->id, $fix, $dryRun, $fixSerials);
            $drifts = count($result['drifts']);
            $serialDrifts = count($result['serial_drifts']);
            $fixed = $result['fixed'];
            $totalDrifts += $drifts;
            $totalSerialDrifts += $serialDrifts;
            $totalFixed += $fixed;
            $this->line("Tenant {$tenant->slug}: {$drifts} drift(s), {$serialDrifts} serial drift(s), {$fixed} fixed.");
        }

        $this->info("Reconciliation complete: {$totalDrifts} drift(s), {$totalSerialDrifts} serial drift(s), {$totalFixed} fixed.");

        $hasUnfixedDrift = ($totalDrifts > 0 && ! ($fix && ! $dryRun))
            || ($totalSerialDrifts > 0 && ! ($fixSerials && ! $dryRun));

        return $hasUnfixedDrift ? self::FAILURE : self::SUCCESS;
    }
}
