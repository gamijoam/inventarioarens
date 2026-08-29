<?php

use App\Modules\Printing\Controllers\PrintConnectorController;
use App\Modules\Printing\Controllers\PrinterStationController;
use App\Modules\Printing\Controllers\PrintJobController;
use App\Modules\Printing\Controllers\PrintProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('printing')->group(function (): void {
    Route::get('connectors', [PrintConnectorController::class, 'index']);
    Route::post('connectors/pairing-codes', [PrintConnectorController::class, 'createPairingCode']);
    Route::post('connectors/{printConnector}/revoke', [PrintConnectorController::class, 'revoke']);

    Route::post('profiles/preview.pdf', [PrintProfileController::class, 'previewPdf']);

    Route::apiResource('profiles', PrintProfileController::class)
        ->parameters(['profiles' => 'printProfile'])
        ->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('stations', PrinterStationController::class)
        ->parameters(['stations' => 'printerStation'])
        ->only(['index', 'store', 'update', 'destroy']);

    Route::get('jobs', [PrintJobController::class, 'index']);
    Route::get('jobs/{printJob}/ticket.html', [PrintJobController::class, 'html']);
    Route::get('jobs/{printJob}/ticket.pdf', [PrintJobController::class, 'pdf']);
    Route::patch('jobs/{printJob}/status', [PrintJobController::class, 'status']);
});

Route::post('pos/orders/{posOrder}/print-jobs', [PrintJobController::class, 'storeForPos']);
