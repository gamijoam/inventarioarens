<?php

use Illuminate\Support\Facades\Route;

require base_path('app/Modules/Auth/routes.php');

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Dashboard/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/InventoryCenter/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Branches/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Currency/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Customers/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Suppliers/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Products/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Warehouses/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Inventory/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/ProductEntries/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/ProductExits/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/InventoryTransfers/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/InventoryTransferRequests/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Reports/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Kardex/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Sales/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/SalesReturns/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Purchases/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/PurchaseReturns/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/AccountsPayable/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/AccountsReceivable/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/PaymentReceipts/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/FinancialAdjustments/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/FinanceReports/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/POS/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/CashRegister/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/AccessControl/routes.php'));

Route::middleware(['api.auth', 'tenant'])
    ->group(base_path('app/Modules/Warranties/routes.php'));
