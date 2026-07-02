<?php

use App\Modules\SalesReturns\Controllers\SalesReturnController;
use Illuminate\Support\Facades\Route;

Route::apiResource('sales-returns', SalesReturnController::class)
    ->only(['index', 'store', 'show']);
