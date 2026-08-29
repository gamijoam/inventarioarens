<?php

use App\Modules\AccountsPayable\Controllers\AccountsPayableController;
use App\Modules\AccountsPayable\Controllers\AccountsPayablePaymentRequestController;
use Illuminate\Support\Facades\Route;

Route::get('accounts-payable/export', [AccountsPayableController::class, 'export']);

Route::apiResource('accounts-payable', AccountsPayableController::class)
    ->parameters(['accounts-payable' => 'accountsPayable'])
    ->only(['index', 'show']);

Route::post('accounts-payable/{accountsPayable}/payments', [AccountsPayableController::class, 'pay'])
    ->middleware('idempotency');
Route::post('accounts-payable/{accountsPayable}/payment-requests', [AccountsPayableController::class, 'preparePaymentRequest'])
    ->middleware('idempotency');

Route::get('accounts-payable-payment-requests', [AccountsPayablePaymentRequestController::class, 'index']);
Route::post('accounts-payable-payment-requests/{paymentRequest}/approve', [AccountsPayablePaymentRequestController::class, 'approve'])
    ->middleware('idempotency');
Route::post('accounts-payable-payment-requests/{paymentRequest}/reject', [AccountsPayablePaymentRequestController::class, 'reject'])
    ->middleware('idempotency');
Route::post('accounts-payable-payment-requests/{paymentRequest}/execute', [AccountsPayablePaymentRequestController::class, 'execute'])
    ->middleware('idempotency');
Route::post('accounts-payable-payment-requests/{paymentRequest}/cancel', [AccountsPayablePaymentRequestController::class, 'cancel'])
    ->middleware('idempotency');
