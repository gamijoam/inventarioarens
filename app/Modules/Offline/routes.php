<?php

use App\Modules\Offline\Controllers\OfflineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.auth', 'tenant'])
    ->prefix('offline')
    ->group(function (): void {
        Route::get('installer-info', [OfflineController::class, 'installerInfo']);
        Route::get('snapshots/latest', [OfflineController::class, 'latestSnapshot']);
        Route::post('snapshots', [OfflineController::class, 'createSnapshot'])->middleware('throttle:3,1');
        Route::get('snapshots/{snapshotId}', [OfflineController::class, 'snapshotStatus'])->whereUuid('snapshotId');
        Route::get('snapshots/{snapshotId}/download', [OfflineController::class, 'downloadSnapshot'])->whereUuid('snapshotId');

        // Rutas antiguas: se mantienen protegidas para responder con una migracion clara.
        Route::get('package', [OfflineController::class, 'legacyDownload']);
        Route::get('database', [OfflineController::class, 'legacyDownload']);
        Route::get('config', [OfflineController::class, 'legacyDownload']);
        Route::post('upload-sales', [OfflineController::class, 'legacyUpload']);
    });
