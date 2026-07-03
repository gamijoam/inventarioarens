<?php

use App\Modules\FinancialAdjustments\Controllers\FinancialAdjustmentController;
use Illuminate\Support\Facades\Route;

Route::apiResource('financial-adjustments', FinancialAdjustmentController::class)
    ->parameters(['financial-adjustments' => 'financialAdjustment'])
    ->only(['index', 'store', 'show']);
