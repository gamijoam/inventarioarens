<?php

use App\Modules\Promotions\Controllers\PosPromotionController;
use App\Modules\Promotions\Controllers\PromotionController;
use App\Modules\Promotions\Models\Promotion;
use Illuminate\Support\Facades\Route;

Route::apiResource('promotions', PromotionController::class)
    ->only(['index', 'store', 'show', 'update', 'destroy']);

Route::get('pos/promotions/available', [PosPromotionController::class, 'available']);

foreach ([
    'invoice-promotions' => Promotion::SCOPE_INVOICE,
    'combos' => Promotion::SCOPE_COMBO,
    'product-offers' => Promotion::SCOPE_PRODUCT_OFFER,
] as $prefix => $scope) {
    Route::get($prefix, [PromotionController::class, 'index'])->defaults('promotion_scope', $scope);
    Route::post($prefix, [PromotionController::class, 'store'])->defaults('promotion_scope', $scope);
    Route::get("{$prefix}/{promotion}", [PromotionController::class, 'show'])->defaults('promotion_scope', $scope);
    Route::patch("{$prefix}/{promotion}", [PromotionController::class, 'update'])->defaults('promotion_scope', $scope);
    Route::delete("{$prefix}/{promotion}", [PromotionController::class, 'destroy'])->defaults('promotion_scope', $scope);
}

Route::get('pos/invoice-promotions', [PosPromotionController::class, 'available'])
    ->defaults('promotion_scope', Promotion::SCOPE_INVOICE);
Route::get('pos/combos', [PosPromotionController::class, 'available'])
    ->defaults('promotion_scope', Promotion::SCOPE_COMBO);
Route::get('pos/product-offers', [PosPromotionController::class, 'available'])
    ->defaults('promotion_scope', Promotion::SCOPE_PRODUCT_OFFER);
