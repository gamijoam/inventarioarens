<?php

use App\Modules\ProductEntries\Controllers\ProductEntryController;
use Illuminate\Support\Facades\Route;

Route::apiResource('product-entries', ProductEntryController::class)
    ->parameters(['product-entries' => 'productEntry'])
    ->only(['index', 'store', 'show']);
