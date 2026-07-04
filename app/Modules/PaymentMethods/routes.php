<?php

use App\Modules\PaymentMethods\Controllers\PaymentMethodController;
use Illuminate\Support\Facades\Route;

Route::apiResource('payment-methods', PaymentMethodController::class)
    ->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['payment-methods' => 'paymentMethod']);
