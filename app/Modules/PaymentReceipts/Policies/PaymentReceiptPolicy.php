<?php

namespace App\Modules\PaymentReceipts\Policies;

use App\Models\User;
use App\Modules\PaymentReceipts\Models\PaymentReceipt;
use App\Support\Tenancy\TenantManager;

class PaymentReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasTenantPermission($user, 'payment_receipts.view');
    }

    public function view(User $user, PaymentReceipt $receipt): bool
    {
        return $this->ownsResource($receipt)
            && $this->hasTenantPermission($user, 'payment_receipts.view');
    }

    public function void(User $user, PaymentReceipt $receipt): bool
    {
        return $this->ownsResource($receipt)
            && $this->hasTenantPermission($user, 'payment_receipts.void');
    }

    private function hasTenantPermission(User $user, string $permission): bool
    {
        $tenant = app(TenantManager::class)->current();

        if (! $tenant || ! $user->belongsToTenant($tenant)) {
            return false;
        }

        setPermissionsTeamId($tenant->id);

        return $user->can($permission);
    }

    private function ownsResource(PaymentReceipt $receipt): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $receipt->tenant_id === (int) $tenant->id;
    }
}
