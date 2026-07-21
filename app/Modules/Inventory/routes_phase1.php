<?php

use App\Modules\Inventory\Controllers\ProductUnitLookupController;
use Illuminate\Support\Facades\Route;

/**
 * Fase 1 - IMEI scanner: endpoints de lectura para ProductUnits.
 * Usado por el frontend para listar IMEIs/seriales disponibles de un
 * almacen y permitir al user seleccionarlos en los dialogs de
 * crear/preparar/recibir traslados.
 */
Route::prefix('inventory-centers')->group(function (): void {
    Route::get('products/{product}/units', [ProductUnitLookupController::class, 'index'])
        ->whereNumber('product');
    Route::get('products/units/lookup', [ProductUnitLookupController::class, 'lookup']);
});