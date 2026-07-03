<?php

use App\Modules\Warranties\Controllers\WarrantyPolicyController;
use Illuminate\Support\Facades\Route;

Route::apiResource('warranty-policies', WarrantyPolicyController::class)
    ->parameters(['warranty-policies' => 'warrantyPolicy'])
    ->only(['index', 'store', 'show', 'update', 'destroy']);
