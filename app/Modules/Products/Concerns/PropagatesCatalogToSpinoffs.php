<?php

namespace App\Modules\Products\Concerns;

use App\Modules\Products\Services\SharedCatalogPropagationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait que replica automaticamente el catalogo del grupo a sus spinoffs
 * cuando el modelo se crea o actualiza desde el grupo (tenant actual es
 * `is_group=true`). Es idempotente: el servicio de propagacion detecta
 * si la copia ya existe en el spinoff (por code/slug/name) y no duplica.
 *
 * Para usar, el modelo debe:
 *   1. Usar este trait.
 *   2. Implementar `propagateToSpinoffs(Model $master): void` que delega
 *      al metodo correcto de SharedCatalogPropagationService.
 *
 * Comportamiento:
 *   - En HTTP requests: la propagacion corre inmediatamente despues del
 *     save. Si falla, se loggea y NO se interrumpe la operacion.
 *   - En tests: la propagacion se DESACTIVA para no contaminar el
 *     scope de cada test. Si el test quiere verificar propagacion,
 *     debe invocar el servicio directamente.
 *   - En jobs/console: la propagacion se difiere via DB::afterCommit
 *     para que la operacion del grupo ya este commiteada antes de
 *     tocar los spinoffs.
 */
trait PropagatesCatalogToSpinoffs
{
    protected static function bootPropagatesCatalogToSpinoffs(): void
    {
        static::saved(function (Model $model): void {
            // Skip en tests para no contaminar transacciones.
            if (app()->runningUnitTests()) {
                return;
            }

            if (! static::shouldPropagate($model)) {
                return;
            }

            $runner = static function () use ($model): void {
                try {
                    static::propagateToSpinoffs($model);
                } catch (\Throwable $e) {
                    if (function_exists('logger')) {
                        logger()->warning('Catalog propagation to spinoffs failed', [
                            'model' => get_class($model),
                            'id' => $model->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            };

            // Si hay transaccion abierta, esperar al commit para no
            // contaminar el savepoint con errores del hook.
            if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                \Illuminate\Support\Facades\DB::afterCommit($runner);

                return;
            }

            $runner();
        });
    }

    protected static function shouldPropagate(Model $model): bool
    {
        $tenant = app(TenantManager::class)->current();

        if (! $tenant) {
            return false;
        }

        if (! method_exists($tenant, 'isGroup') || ! $tenant->isGroup()) {
            return false;
        }

        $spinoffCount = Tenant::query()
            ->where('parent_id', $tenant->id)
            ->where('is_group', false)
            ->count();

        return $spinoffCount > 0;
    }

    abstract protected static function propagateToSpinoffs(Model $model): void;
}
