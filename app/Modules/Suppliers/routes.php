<?php

use App\Modules\Suppliers\Controllers\SupplierController;
use App\Modules\Suppliers\Controllers\SupplierCreditController;
use Illuminate\Support\Facades\Route;

Route::apiResource('suppliers', SupplierController::class);

Route::get('suppliers/{supplier}/credit', [SupplierCreditController::class, 'show']);
Route::post('suppliers/{supplier}/credit-refunds', [SupplierCreditController::class, 'refund'])
    ->middleware('idempotency');
