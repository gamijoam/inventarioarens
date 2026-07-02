<?php

namespace App\Providers;

use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Policies\BranchPolicy;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Policies\CashRegisterSessionPolicy;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Policies\CustomerPolicy;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Currency\Policies\ExchangeRatePolicy;
use App\Modules\Currency\Policies\ExchangeRateTypePolicy;
use App\Modules\Inventory\Policies\InventoryPolicy;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Policies\PosOrderPolicy;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Policies\ProductPolicy;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Policies\SalePolicy;
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
        Gate::policy(CashRegisterSession::class, CashRegisterSessionPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(ExchangeRate::class, ExchangeRatePolicy::class);
        Gate::policy(ExchangeRateType::class, ExchangeRateTypePolicy::class);
        Gate::policy(PosOrder::class, PosOrderPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::define('inventory.view-operation', [InventoryPolicy::class, 'view']);
        Gate::define('inventory.receive-operation', [InventoryPolicy::class, 'receive']);
        Gate::define('inventory.sale-operation', [InventoryPolicy::class, 'sale']);
        Gate::define('inventory.adjust-operation', [InventoryPolicy::class, 'adjust']);
        Gate::define('inventory.transfer-operation', [InventoryPolicy::class, 'transfer']);
    }
}
