<?php

namespace App\Providers;

use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePaymentRequest;
use App\Modules\AccountsPayable\Policies\AccountsPayablePaymentRequestPolicy;
use App\Modules\AccountsPayable\Policies\AccountsPayablePolicy;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Policies\AccountsReceivablePolicy;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Policies\BranchPolicy;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Policies\CashRegisterPolicy;
use App\Modules\CashRegister\Policies\CashRegisterSessionPolicy;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Currency\Policies\ExchangeRatePolicy;
use App\Modules\Currency\Policies\ExchangeRateTypePolicy;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Policies\CustomerPolicy;
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
use App\Support\Cache\TenantReferenceCacheInvalidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
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
        Gate::policy(AccountsPayable::class, AccountsPayablePolicy::class);
        Gate::policy(AccountsPayablePaymentRequest::class, AccountsPayablePaymentRequestPolicy::class);
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
        Gate::define('inventory.manual-movement-operation', [InventoryPolicy::class, 'approveManualMovement']);

        $this->configureRateLimiters();
        $this->configureHttpClientTls();

        TenantReferenceCacheInvalidator::register();
    }

    /**
     * Asegura que los clientes HTTP (Guzzle vía Laravel Http) tengan un
     * bundle de CA válido para verificar TLS contra la nube.
     *
     * En los clientes Electron el runtime PHP trae un cacert.pem junto al
     * binario, pero el proceso puede arrancar sin `PHP_INI_SCAN_DIR` (por
     * ejemplo si se reinicia el backend sin re-lanzar el supervisor), con lo
     * que `curl.cainfo` queda vacío y TODOS los requests HTTPS fallan con
     * "cURL error 77/60: unable to get local issuer certificate". Ese fallo
     * hacía que las imágenes subidas en local nunca publicaran su binario a
     * la nube (publishImageToCloud reportaba el error y seguía).
     *
     * Aquí seteamos curl.cainfo / openssl.cafile a nivel de proceso SI el
     * runtime trae un cacert.pem válido, independiente de la config INI.
     */
    private function configureHttpClientTls(): void
    {
        $cacert = $this->resolveCacertPath();

        if ($cacert === null) {
            return;
        }

        if (function_exists('curl_version')) {
            $current = ini_get('curl.cainfo') ?: '';
            if ($current === '' || ! is_file($current)) {
                @ini_set('curl.cainfo', $cacert);
            }
        }

        if (function_exists('openssl_get_cert_locations')) {
            $locations = openssl_get_cert_locations();
            $current = $locations['default_cert_file'] ?? '';
            if ($current === '' || ! is_file($current)) {
                @ini_set('openssl.cafile', $cacert);
            }
        }

        // Guzzle lee curl.cainfo en el momento de crear cada client; forzar
        // el CA en las opciones globales de Laravel Http como respaldo.
        Http::globalOptions([
            'verify' => $cacert,
        ]);
    }

    /**
     * Resuelve el bundle de CA a usar para los clientes HTTP.
     * Prioridad:
     *  1. Config SYNC_TLS_CACERT (override explicito del operador).
     *  2. runtime/php/cacert.pem relativo al repo (cliente Electron).
     *  3. cacert.pem junto al binario PHP actual (dirname(PHP_BINARY)).
     */
    private function resolveCacertPath(): ?string
    {
        $candidates = [
            config('services.sync.tls_cacert'),
            base_path('runtime/php/cacert.pem'),
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'cacert.pem',
        ];

        foreach (array_filter($candidates) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('auth', function (Request $request): array {
            $email = strtolower((string) $request->input('email', ''));
            $key = $request->ip().'|'.$email;

            return [
                Limit::perMinute(5)->by($key)->response(function (): JsonResponse {
                    return response()->json([
                        'message' => 'Demasiados intentos de autenticación. Por favor intente en 1 minuto.',
                    ], 429);
                }),
            ];
        });

        RateLimiter::for('bootstrap', function (Request $request): array {
            return [
                Limit::perHour(3)->by($request->ip())->response(function (): JsonResponse {
                    return response()->json([
                        'message' => 'Demasiados intentos de bootstrap. Por favor intente en 1 hora.',
                    ], 429);
                }),
            ];
        });
    }
}
