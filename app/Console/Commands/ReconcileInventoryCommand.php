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
        {--dry-run : Reporta los cambios sin modificar datos}';

    protected $description = 'Compara stock_balances contra el ledger de stock_movements.';

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
        $totalFixed = 0;
        $fix = (bool) $this->option('fix');
        $dryRun = (bool) $this->option('dry-run');

        foreach ($tenants as $tenant) {
            $result = $service->reconcileTenant($tenant->id, $fix, $dryRun);
            $drifts = count($result['drifts']);
            $fixed = $result['fixed'];
            $totalDrifts += $drifts;
            $totalFixed += $fixed;
            $this->line("Tenant {$tenant->slug}: {$drifts} drift(s), {$fixed} fixed.");
        }

        $this->info("Reconciliation complete: {$totalDrifts} drift(s), {$totalFixed} fixed.");

        return $totalDrifts > 0 && ! ($fix && ! $dryRun) ? self::FAILURE : self::SUCCESS;
    }
}
