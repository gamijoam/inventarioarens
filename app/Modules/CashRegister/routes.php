<?php

use App\Modules\CashRegister\Controllers\CashRegisterController;
use App\Modules\CashRegister\Controllers\CashRegisterSessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('cash-register')->group(function (): void {
    Route::get('registers', [CashRegisterController::class, 'index']);
    Route::post('registers', [CashRegisterController::class, 'store']);
    Route::patch('registers/{cashRegister}', [CashRegisterController::class, 'update']);
    Route::get('sessions', [CashRegisterSessionController::class, 'index']);
    Route::post('sessions', [CashRegisterSessionController::class, 'open']);
    Route::get('sessions/{cashRegisterSession}', [CashRegisterSessionController::class, 'show']);
    Route::post('sessions/{cashRegisterSession}/movements', [CashRegisterSessionController::class, 'movement']);
    Route::patch('sessions/{cashRegisterSession}/close', [CashRegisterSessionController::class, 'close']);
});
