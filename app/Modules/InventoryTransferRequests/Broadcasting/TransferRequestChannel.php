<?php

namespace App\Modules\InventoryTransferRequests\Broadcasting;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Canal privado de broadcasting para tenants.
 *
 * Laravel invoca este policy cuando el cliente intenta suscribirse a
 * `private-tenant.{tenant_id}`. Retornamos true solo si el user
 * autenticado pertenece al tenant del canal Y el tenant esta activo.
 *
 * Esto blinda contra suscripciones fraudulentas: si un user de danubio
 * intenta escuchar notificaciones de danubio-soledad, este policy
 * rechaza la suscripcion silenciosamente.
 */
class TransferRequestChannel
{
    /**
     * Laravel pasa el canal parseado a este metodo. Formato esperado:
     * `private-tenant.{id}` con `id` siendo el ID del tenant destino.
     */
    public function join(User $user, string $tenantId): bool
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant || $tenant->status !== 'active') {
            return false;
        }

        return $user->tenants()
            ->where('tenant_user.tenant_id', $tenant->id)
            ->where('tenant_user.status', 'active')
            ->exists();
    }
}
