<?php

use App\Modules\AccountsPayable\Controllers\AccountsPayableController;
use Illuminate\Support\Facades\Route;

Route::apiResource('accounts-payable', AccountsPayableController::class)
    ->parameters(['accounts-payable' => 'accountsPayable'])
    ->only(['index', 'show']);

Route::post('accounts-payable/{accountsPayable}/payments', [AccountsPayableController::class, 'pay']);
