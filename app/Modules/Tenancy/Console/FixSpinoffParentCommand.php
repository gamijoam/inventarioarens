<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Reasigna el parent_id de un spinoff a otro grupo.
 *
 * Caso de uso: cuando un spinoff se creo accidentalmente bajo el grupo
 * equivocado (ej. se selecciono "grupo-prueba" en vez de "danubio" al
 * crear la empresa). Este comando permite mover el spinoff al grupo
 * correcto sin perder sus datos, membresias ni roles.
 *
 * Uso:
 *   php artisan tenancy:fix-spinoff-parent {spinoff_slug_or_id} {new_parent_slug_or_id}
 *   php artisan tenancy:fix-spinoff-parent danubio-empresa danubio
 *
 * Por seguridad, el comando:
 *  - Verifica que el spinoff sea efectivamente un spinoff (no un grupo).
 *  - Verifica que el nuevo parent sea un grupo (is_group=true).
 *  - No permite reasignar un grupo (los grupos no tienen parent).
 *  - Confirma antes de ejecutar el cambio.
 */
class FixSpinoffParentCommand extends Command
{
    protected $signature = 'tenancy:fix-spinoff-parent
        {spinoff : Slug o id del spinoff a reasignar}
        {new_parent : Slug o id del nuevo grupo padre}
        {--yes : Confirmar sin pedir interaccion}';

    protected $description = 'Reasigna el parent_id de un spinoff a otro grupo. Util para corregir jerarquias mal asignadas.';

    public function handle(): int
    {
        $spinoffInput = $this->argument('spinoff');
        $newParentInput = $this->argument('new_parent');

        $spinoff = $this->resolveTenant($spinoffInput);
        $newParent = $this->resolveTenant($newParentInput);

        if (! $spinoff) {
            $this->error("Spinoff '{$spinoffInput}' no encontrado.");

            return self::FAILURE;
        }
        if (! $newParent) {
            $this->error("Nuevo parent '{$newParentInput}' no encontrado.");

            return self::FAILURE;
        }

        if ($spinoff->isGroup()) {
            $this->error("'{$spinoff->slug}' (id={$spinoff->id}) es un GRUPO raiz, no un spinoff. No se puede reasignar.");

            return self::FAILURE;
        }
        if (! $newParent->isGroup()) {
            $this->error("'{$newParent->slug}' (id={$newParent->id}) no es un grupo (is_group=false). El parent debe ser un grupo raiz.");

            return self::FAILURE;
        }
        if ($spinoff->id === $newParent->id) {
            $this->error('Spinoff y nuevo parent son el mismo tenant.');

            return self::FAILURE;
        }
        if ($spinoff->parent_id === $newParent->id) {
            $this->info("'{$spinoff->slug}' ya es hijo de '{$newParent->slug}'. Nada que hacer.");

            return self::SUCCESS;
        }

        $oldParent = $spinoff->parent_id
            ? Tenant::find($spinoff->parent_id)
            : null;

        $this->info('Reasignacion de spinoff:');
        $this->line("  Spinoff:   {$spinoff->slug} (id={$spinoff->id})");
        $this->line('  Old parent: '.($oldParent ? "{$oldParent->slug} (id={$oldParent->id})" : 'NULL'));
        $this->line("  New parent: {$newParent->slug} (id={$newParent->id})");

        if (! $this->option('yes') && ! $this->confirm('Continuar con la reasignacion?', false)) {
            $this->warn('Cancelado por el usuario.');

            return self::FAILURE;
        }

        $spinoff->parent_id = $newParent->id;
        $spinoff->save();

        $this->info("OK: '{$spinoff->slug}' ahora es hijo de '{$newParent->slug}'.");

        return self::SUCCESS;
    }

    private function resolveTenant(string $slugOrId): ?Tenant
    {
        if (is_numeric($slugOrId)) {
            return Tenant::find((int) $slugOrId);
        }

        return Tenant::where('slug', $slugOrId)->first();
    }
}
