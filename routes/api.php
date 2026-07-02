<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/Branches/routes.php'));

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/Currency/routes.php'));

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/Customers/routes.php'));

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/Products/routes.php'));

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/Warehouses/routes.php'));

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/Inventory/routes.php'));

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/Reports/routes.php'));

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/Sales/routes.php'));

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/POS/routes.php'));

Route::middleware(['auth', 'tenant'])
    ->group(base_path('app/Modules/CashRegister/routes.php'));
