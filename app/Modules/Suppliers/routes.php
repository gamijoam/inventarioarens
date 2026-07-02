<?php

use App\Modules\Suppliers\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::apiResource('suppliers', SupplierController::class);
