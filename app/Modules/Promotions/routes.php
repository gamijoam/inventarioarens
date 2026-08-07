<?php

use App\Modules\Promotions\Controllers\PosPromotionController;
use App\Modules\Promotions\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::apiResource('promotions', PromotionController::class)
    ->only(['index', 'store', 'show', 'update', 'destroy']);

Route::get('pos/promotions/available', [PosPromotionController::class, 'available']);
