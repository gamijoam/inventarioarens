<?php

namespace App\Support\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Politica de escritura sobre catalogos compartidos a nivel grupo.
 *
 * Regla:
 *   - Owner del grupo (parent del tenant actual) puede escribir.
 *   - User trabajando directamente sobre el tenant raiz del grupo puede escribir.
 *   - Cualquier otra operacion contra un catalogo compartido desde un spinoff
 *     se rechaza con 403 (Forbidden).
 *
 * Esto aplica a entidades que viven en la capa jerarquica
 * (BelongsToTenantHierarchy): products, brands, categories, tags, price_lists,
 * product_prices, payment_methods, exchange_rate_types y exchange_rates.
 */
trait SharedCatalogWriteGuard
{
    /**
     * Devuelve el grupo raiz que actua como catalogo compartido para el
     * tenant actual, o null si el tenant actual no pertenece a un grupo.
     */
    protected function sharedCatalogGroup(): ?Tenant
    {
        return app(TenantManager::class)->sharedTenant();
    }

    /**
     * Verifica que el user autenticado pueda escribir catalogos compartidos.
     * Retorna true cuando:
     *   - el tenant actual es el mismo tenant raiz del grupo (Owner operando
     *     el grupo), o
     *   - el user es Owner estricto del grupo padre (administracion central).
     */
    protected function canWriteSharedCatalog(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $manager = app(TenantManager::class);
        $current = $manager->current();

        if ($current === null) {
            return false;
        }

        // Si el user ya esta parado sobre el grupo raiz, tiene control directo.
        if ($current->isGroup()) {
            return $user->belongsToTenant($current);
        }

        // Spinoff: solo el Owner del grupo padre puede tocar el catalogo.
        $parent = $this->sharedCatalogGroup();
        if ($parent === null || $parent->id === $current->id) {
            return false;
        }

        return $user->isStrictOwnerOf($parent);
    }

    /**
     * Verifica que el $modelo pertenezca al grupo compartido.
     * Util para bloquear PATCH/PUT/DELETE contra un producto que ya vive en
     * el grupo cuando el request se hace desde un spinoff.
     */
    protected function modelBelongsToSharedCatalog(Model $model): bool
    {
        $group = $this->sharedCatalogGroup();
        if ($group === null) {
            return false;
        }

        return (int) $model->getAttribute('tenant_id') === (int) $group->id;
    }
}