<?php

use App\Modules\Customers\Controllers\CustomerController;
use App\Modules\Customers\Controllers\CustomerGroupController;
use Illuminate\Support\Facades\Route;

Route::apiResource('customers', CustomerController::class);
Route::get('customer-groups', [CustomerGroupController::class, 'index']);
Route::get('customer-groups/{customerGroup}', [CustomerGroupController::class, 'show']);
