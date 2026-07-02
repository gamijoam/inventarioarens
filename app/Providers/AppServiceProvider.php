<?php

namespace App\Providers;

use App\Modules\Inventory\Policies\InventoryPolicy;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Policies\BranchPolicy;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Policies\ProductPolicy;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warehouses\Policies\WarehousePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::define('inventory.view-operation', [InventoryPolicy::class, 'view']);
        Gate::define('inventory.receive-operation', [InventoryPolicy::class, 'receive']);
        Gate::define('inventory.sale-operation', [InventoryPolicy::class, 'sale']);
        Gate::define('inventory.adjust-operation', [InventoryPolicy::class, 'adjust']);
        Gate::define('inventory.transfer-operation', [InventoryPolicy::class, 'transfer']);
    }
}
