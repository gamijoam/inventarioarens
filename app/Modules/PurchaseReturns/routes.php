<?php

use App\Modules\PurchaseReturns\Controllers\PurchaseReturnController;
use Illuminate\Support\Facades\Route;

Route::apiResource('purchase-returns', PurchaseReturnController::class)
    ->only(['index', 'store', 'show']);
