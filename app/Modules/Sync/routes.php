<?php

use App\Modules\Sync\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('sync')->group(function (): void {
    Route::post('tokens', [SyncController::class, 'issueToken']);
    Route::post('pairing-codes', [SyncController::class, 'createPairingCode']);
    Route::post('group-pairing-codes', [SyncController::class, 'createGroupPairingCode']);
    Route::post('nodes', [SyncController::class, 'registerNode']);
    Route::post('bootstrap', [SyncController::class, 'startBootstrap']);
    Route::post('bootstrap/{sessionToken}/complete', [SyncController::class, 'completeBootstrap']);
    Route::post('events/push', [SyncController::class, 'push']);
    Route::get('events/pull', [SyncController::class, 'pull']);
    Route::post('events/{eventUuid}/ack', [SyncController::class, 'acknowledge']);
    Route::post('images', [SyncController::class, 'uploadImage']);
    Route::get('status', [SyncController::class, 'status']);
    Route::get('local-readiness', [SyncController::class, 'readiness']);
    Route::post('local-readiness', [SyncController::class, 'markReadiness']);
});
