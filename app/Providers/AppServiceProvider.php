<?php

namespace App\Providers;

use App\Modules\Branches\Models\Branch;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Policies\AccountsPayablePolicy;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Policies\AccountsReceivablePolicy;
use App\Modules\Branches\Policies\BranchPolicy;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Policies\CashRegisterPolicy;
use App\Modules\CashRegister\Policies\CashRegisterSessionPolicy;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Policies\CustomerPolicy;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Currency\Policies\ExchangeRatePolicy;
use App\Modules\Currency\Policies\ExchangeRateTypePolicy;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\FinancialAdjustments\Policies\FinancialAdjustmentPolicy;
use App\Modules\Inventory\Policies\InventoryPolicy;
use App\Modules\InventoryTransferRequests\Models\InventoryTransferRequest;
use App\Modules\InventoryTransferRequests\Policies\InventoryTransferRequestPolicy;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use App\Modules\InventoryTransfers\Policies\InventoryTransferPolicy;
use App\Modules\PaymentReceipts\Models\PaymentReceipt;
use App\Modules\PaymentReceipts\Policies\PaymentReceiptPolicy;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Policies\PosOrderPolicy;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductEntries\Policies\ProductEntryPolicy;
use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\ProductExits\Policies\ProductExitPolicy;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Policies\ProductPolicy;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\PurchaseReturns\Policies\PurchaseReturnPolicy;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Policies\PurchaseOrderPolicy;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Policies\SalePolicy;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Policies\SalesReturnPolicy;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Policies\SupplierPolicy;
use App\Modules\Warehouses\Models\Warehouse;
use App\Modules\Warehouses\Policies\WarehousePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        $this->assertNoBypassLoginOutsideLocal();

        Gate::policy(AccountsPayable::class, AccountsPayablePolicy::class);
        Gate::policy(AccountsReceivable::class, AccountsReceivablePolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(CashRegister::class, CashRegisterPolicy::class);
        Gate::policy(CashRegisterSession::class, CashRegisterSessionPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(ExchangeRate::class, ExchangeRatePolicy::class);
        Gate::policy(ExchangeRateType::class, ExchangeRateTypePolicy::class);
        Gate::policy(FinancialAdjustment::class, FinancialAdjustmentPolicy::class);
        Gate::policy(InventoryTransferRequest::class, InventoryTransferRequestPolicy::class);
        Gate::policy(InventoryTransfer::class, InventoryTransferPolicy::class);
        Gate::policy(PaymentReceipt::class, PaymentReceiptPolicy::class);
        Gate::policy(PosOrder::class, PosOrderPolicy::class);
        Gate::policy(ProductEntry::class, ProductEntryPolicy::class);
        Gate::policy(ProductExit::class, ProductExitPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(PurchaseReturn::class, PurchaseReturnPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);
        Gate::policy(SalesReturn::class, SalesReturnPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::define('inventory.view-operation', [InventoryPolicy::class, 'view']);
        Gate::define('inventory.receive-operation', [InventoryPolicy::class, 'receive']);
        Gate::define('inventory.sale-operation', [InventoryPolicy::class, 'sale']);
        Gate::define('inventory.adjust-operation', [InventoryPolicy::class, 'adjust']);
        Gate::define('inventory.transfer-operation', [InventoryPolicy::class, 'transfer']);

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('auth', function (Request $request): array {
            $email = strtolower((string) $request->input('email', ''));
            $key = $request->ip().'|'.$email;

            return [
                Limit::perMinute(5)->by($key)->response(function (): \Illuminate\Http\JsonResponse {
                    return response()->json([
                        'message' => 'Demasiados intentos de autenticación. Por favor intente en 1 minuto.',
                    ], 429);
                }),
            ];
        });
    }

    /**
     * Defensa en profundidad: el bypass login solo puede existir en entorno local.
     * Si en producción (testing/staging/production) está activo, falla loud en boot.
     */
    private function assertNoBypassLoginOutsideLocal(): void
    {
        if (! app()->environment('local') && (bool) env('FRONTEND_DEV_BYPASS_LOGIN', false)) {
            throw new RuntimeException(
                'FRONTEND_DEV_BYPASS_LOGIN solo puede estar activo cuando APP_ENV=local. '
                .'Entorno actual: '.app()->environment().'. Verifica tu archivo .env.'
            );
        }
    }
}
