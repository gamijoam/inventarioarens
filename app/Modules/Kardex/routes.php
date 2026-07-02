<?php

use App\Modules\Kardex\Controllers\KardexController;
use Illuminate\Support\Facades\Route;

Route::prefix('kardex')->group(function (): void {
    Route::get('products/{product}', [KardexController::class, 'product']);
});
