<?php

namespace App\Console\Commands;

use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

class ReconcileSharedCatalogCommand extends Command
{
    protected $signature = 'catalog:reconcile
        {--group= : ID o slug del grupo a reconciliar (opcional, default: todos)}
        {--dry-run : Muestra los grupos y spinoffs sin modificar datos}';

    protected $description = 'Reconcilia el catalogo compartido completo hacia todos los spinoffs.';

    public function handle(SharedCatalogPropagationService $service): int
    {
        $groupOption = $this->option('group');
        $groups = Tenant::query()
            ->where('is_group', true)
            ->when($groupOption !== null, function ($query) use ($groupOption): void {
                $query->where(function ($nested) use ($groupOption): void {
                    $nested->where('id', (int) $groupOption)
                        ->orWhere('slug', (string) $groupOption);
                });
            })
            ->get();

        if ($groups->isEmpty()) {
            $this->error('No se encontro el grupo indicado.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $spinoffCount = 0;

        foreach ($groups as $group) {
            $spinoffs = Tenant::query()
                ->where('parent_id', $group->id)
                ->where('is_group', false)
                ->get();

            $this->info(sprintf(
                'Grupo %s (%d): %d spinoff(s) %s',
                $group->slug,
                $group->id,
                $spinoffs->count(),
                $dryRun ? '(dry-run)' : '',
            ));

            foreach ($spinoffs as $spinoff) {
                $spinoffCount++;
                $this->line(sprintf('  - %s (%d)', $spinoff->slug, $spinoff->id));

                if (! $dryRun) {
                    $service->propagateAllToSpinoff($group, $spinoff);
                }
            }
        }

        $this->info(sprintf(
            'Listo. %d spinoff(s) %s.',
            $spinoffCount,
            $dryRun ? 'serian reconciliados' : 'reconciliados',
        ));

        return self::SUCCESS;
    }
}
