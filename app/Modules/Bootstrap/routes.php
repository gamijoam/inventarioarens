<?php

use App\Modules\Bootstrap\Controllers\BootstrapController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:bootstrap')->group(function (): void {
    Route::post('bootstrap', [BootstrapController::class, 'store']);
});
