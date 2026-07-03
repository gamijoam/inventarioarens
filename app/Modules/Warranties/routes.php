<?php

use App\Modules\Warranties\Controllers\WarrantyPolicyController;
use App\Modules\Warranties\Controllers\WarrantyClaimController;
use Illuminate\Support\Facades\Route;

Route::apiResource('warranty-policies', WarrantyPolicyController::class)
    ->parameters(['warranty-policies' => 'warrantyPolicy'])
    ->only(['index', 'store', 'show', 'update', 'destroy']);

Route::apiResource('warranty-claims', WarrantyClaimController::class)
    ->parameters(['warranty-claims' => 'warrantyClaim'])
    ->only(['index', 'store', 'show']);
Route::patch('warranty-claims/{warrantyClaim}/review', [WarrantyClaimController::class, 'review']);
Route::patch('warranty-claims/{warrantyClaim}/deliver', [WarrantyClaimController::class, 'deliver']);
