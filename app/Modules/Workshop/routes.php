<?php

use App\Modules\Workshop\Controllers\ServiceOrderController;
use Illuminate\Support\Facades\Route;

Route::get('service-orders', [ServiceOrderController::class, 'index']);
Route::post('service-orders', [ServiceOrderController::class, 'store']);
Route::get('service-orders/{serviceOrder}', [ServiceOrderController::class, 'show']);
Route::patch('service-orders/{serviceOrder}', [ServiceOrderController::class, 'update']);
Route::post('service-orders/{serviceOrder}/diagnose', [ServiceOrderController::class, 'diagnose']);
Route::post('service-orders/{serviceOrder}/assign-technician', [ServiceOrderController::class, 'assignTechnician']);
Route::post('service-orders/{serviceOrder}/parts', [ServiceOrderController::class, 'addPart']);
Route::delete('service-orders/{serviceOrder}/parts/{part}', [ServiceOrderController::class, 'removePart']);
Route::post('service-orders/{serviceOrder}/complete', [ServiceOrderController::class, 'complete']);
Route::post('service-orders/{serviceOrder}/cancel', [ServiceOrderController::class, 'cancel']);
