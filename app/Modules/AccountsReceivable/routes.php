<?php

use App\Modules\AccountsReceivable\Controllers\AccountsReceivableController;
use Illuminate\Support\Facades\Route;

Route::apiResource('accounts-receivable', AccountsReceivableController::class)
    ->parameters(['accounts-receivable' => 'accountsReceivable'])
    ->only(['index', 'show']);

Route::post('accounts-receivable/{accountsReceivable}/payments', [AccountsReceivableController::class, 'collect']);
