<?php

use App\Modules\Offline\Controllers\OfflineController;
use Illuminate\Support\Facades\Route;

Route::prefix('offline')->group(function () {
    Route::get('package', [OfflineController::class, 'exportPackage']);
    Route::get('database', [OfflineController::class, 'exportDatabase']);
    Route::get('config', [OfflineController::class, 'exportConfig']);
    Route::post('upload-sales', [OfflineController::class, 'uploadSales']);
});
