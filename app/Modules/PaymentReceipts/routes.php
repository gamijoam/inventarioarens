<?php

use App\Modules\PaymentReceipts\Controllers\PaymentReceiptController;
use Illuminate\Support\Facades\Route;

Route::apiResource('payment-receipts', PaymentReceiptController::class)
    ->parameters(['payment-receipts' => 'paymentReceipt'])
    ->only(['index', 'show']);

Route::patch('payment-receipts/{paymentReceipt}/void', [PaymentReceiptController::class, 'void']);
