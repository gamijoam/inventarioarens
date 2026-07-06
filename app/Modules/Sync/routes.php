<?php

use App\Modules\Sync\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('sync')->group(function (): void {
    Route::post('nodes', [SyncController::class, 'registerNode']);
    Route::post('events/push', [SyncController::class, 'push']);
    Route::get('events/pull', [SyncController::class, 'pull']);
    Route::post('events/{eventUuid}/ack', [SyncController::class, 'acknowledge']);
    Route::get('status', [SyncController::class, 'status']);
    Route::get('local-readiness', [SyncController::class, 'readiness']);
    Route::post('local-readiness', [SyncController::class, 'markReadiness']);
});
