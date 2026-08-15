<?php

use App\Modules\Commissions\Controllers\CommissionAdjustmentController;
use App\Modules\Commissions\Controllers\CommissionApprovalController;
use App\Modules\Commissions\Controllers\CommissionControlController;
use App\Modules\Commissions\Controllers\CommissionEntryController;
use App\Modules\Commissions\Controllers\CommissionExportController;
use App\Modules\Commissions\Controllers\CommissionPlanController;
use App\Modules\Commissions\Controllers\CommissionSettlementController;
use App\Modules\Commissions\Controllers\CommissionSimulatorController;
use Illuminate\Support\Facades\Route;

Route::apiResource('commission-plans', CommissionPlanController::class)
    ->only(['index', 'store', 'show', 'update', 'destroy']);
Route::post('commissions/simulate', CommissionSimulatorController::class);
Route::post('commissions/approve', CommissionApprovalController::class);
Route::post('commissions/adjustments', CommissionAdjustmentController::class);
Route::get('commissions/export', CommissionExportController::class);
Route::get('commissions/control', CommissionControlController::class);
Route::get('commissions', [CommissionEntryController::class, 'index']);
Route::get('commissions/mine', [CommissionEntryController::class, 'mine']);
Route::get('commission-settlements', [CommissionSettlementController::class, 'index']);
Route::post('commission-settlements', [CommissionSettlementController::class, 'store']);
