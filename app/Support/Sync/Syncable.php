<?php

namespace App\Support\Sync;

use App\Modules\Sync\Services\SyncCatalogOutboxService;
use App\Support\Tenancy\TenantManager;

/**
 * Trait Syncable — emite eventos de sync automáticamente cuando un modelo de
 * negocio se crea, actualiza o elimina.
 *
 * Cómo usarlo:
 *   1. `use Syncable;` en el modelo.
 *   2. Implementar `syncOutboxMethod(string $action): ?string` devolviendo el
 *      nombre del método de `SyncCatalogOutboxService` a invocar, o `null`
 *      si esa acción no debe sincronizar.
 *
 * Ejemplo:
 *   class AccountsPayable extends Model {
 *       use BelongsToTenant, Syncable;
 *       protected function syncOutboxMethod(string $action): ?string {
 *           return match ($action) {
 *               'created' => 'accountsPayableCreated',
 *               'updated' => 'accountsPayableUpdated',
 *               default => null,
 *           };
 *       }
 *   }
 *
 * Garantías:
 *  - El evento se emite sin importar si el cambio viene de un controller, un
 *    comando artisan, un seeder o un data-fix: el modelo se sincroniza solo.
 *  - El applier de la nube usa DB::table() (query builder puro), por lo que NO
 *    dispara observers ni genera bucles local<->nube.
 *  - La idempotencia la resuelve SyncOutboxService (mismo aggregate + version
 *    -> misma idempotency_key -> se deduplica).
 *
 * Para silenciar puntualmente una emisión (p.ej. un backfill masivo), usar:
 *   static::syncableSuspended(function () { ... });
 */
trait Syncable
{
    protected static int $syncSuspendedDepth = 0;

    protected static function bootSyncable(): void
    {
        static::created(function ($model): void {
            $model->dispatchSyncEvent('created');
        });

        static::updated(function ($model): void {
            $model->dispatchSyncEvent('updated');
        });

        static::deleted(function ($model): void {
            $model->dispatchSyncEvent('deleted');
        });
    }

    /**
     * Punto de entrada de cada hook. Si el sync está suspendido para este
     * modelo o no hay un tenant resuelto (p.ej. creación fuera de un request
     * tenant-scoped), no hace nada.
     */
    protected function dispatchSyncEvent(string $action): void
    {
        if (static::syncIsSuspended()) {
            return;
        }

        if (app(TenantManager::class)->current() === null) {
            return;
        }

        $method = $this->syncOutboxMethod($action);

        if ($method === null) {
            return;
        }

        app(SyncCatalogOutboxService::class)->{$method}($this);
    }

    /**
     * Nombre del método de SyncCatalogOutboxService a invocar por acción,
     * o null si la acción no sincroniza.
     */
    protected function syncOutboxMethod(string $action): ?string
    {
        return null;
    }

    /**
     * Ejecuta un callback con el sync suspendido para esta clase.
     * Útil para backfills, importaciones masivas o fixes de datos donde no se
     * quiere emitir eventos.
     */
    public static function syncableSuspended(callable $callback): mixed
    {
        static::$syncSuspendedDepth ??= 0;
        static::$syncSuspendedDepth++;

        try {
            return $callback();
        } finally {
            static::$syncSuspendedDepth--;
        }
    }

    protected static function syncIsSuspended(): bool
    {
        return (static::$syncSuspendedDepth ?? 0) > 0;
    }
}
