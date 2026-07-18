<?php

use App\Modules\Bootstrap\Controllers\BootstrapController;
use Illuminate\Support\Facades\Route;

Route::get('bootstrap/status', [BootstrapController::class, 'status']);

Route::middleware('throttle:bootstrap')->group(function (): void {
    Route::post('bootstrap', [BootstrapController::class, 'store']);
});
