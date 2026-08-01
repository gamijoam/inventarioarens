<?php

namespace App\Modules\Products\Policies;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Support\Tenancy\TenantManager;

class ProductVariantPolicy
{
    public function viewAny(User $user, Product $product): bool
    {
        return $this->sameTenant($user, $product) && $user->can('products.view');
    }

    public function create(User $user, Product $product): bool
    {
        return $this->sameTenant($user, $product) && $user->can('products.update');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->sameTenant($user, $product) && $user->can('products.update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->sameTenant($user, $product) && $user->can('products.update');
    }

    private function sameTenant(User $user, Product $product): bool
    {
        $tenant = app(TenantManager::class)->current();

        return $tenant && (int) $product->tenant_id === (int) $tenant->id;
    }
}
