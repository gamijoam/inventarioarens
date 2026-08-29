<?php

use App\Modules\Printing\Controllers\PrintConnectorController;
use Illuminate\Support\Facades\Route;

Route::prefix('printing')->group(function (): void {
    Route::post('connectors/register', [PrintConnectorController::class, 'register'])
        ->middleware('throttle:auth');

    Route::middleware('print.connector')->prefix('connector')->group(function (): void {
        Route::get('heartbeat', [PrintConnectorController::class, 'heartbeat']);
        Route::get('jobs', [PrintConnectorController::class, 'jobs']);
        Route::get('jobs/{jobUuid}/ticket.pdf', [PrintConnectorController::class, 'ticketPdf']);
        Route::post('jobs/{jobUuid}/claim', [PrintConnectorController::class, 'claim']);
        Route::post('jobs/{jobUuid}/ack', [PrintConnectorController::class, 'acknowledge']);
    });
});
