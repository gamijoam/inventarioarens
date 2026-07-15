<?php

use App\Modules\InventoryTransfers\Controllers\InventoryTransferController;
use App\Modules\InventoryTransfers\Controllers\InventoryTransferGuideController;
use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use Illuminate\Support\Facades\Route;

Route::post('inventory-transfers/{inventoryTransfer}/prepare', [InventoryTransferController::class, 'prepare']);
Route::post('inventory-transfers/{inventoryTransfer}/dispatch', [InventoryTransferController::class, 'dispatch']);
Route::post('inventory-transfers/{inventoryTransfer}/receive', [InventoryTransferController::class, 'receive']);
Route::post('inventory-transfers/{inventoryTransfer}/cancel', [InventoryTransferController::class, 'cancel']);
Route::post('inventory-transfers/{inventoryTransfer}/resolve-differences', [InventoryTransferController::class, 'resolveDifferences']);

// FASE T1: driver + checklist interactivo.
Route::put('inventory-transfers/{inventoryTransfer}/driver', [InventoryTransferController::class, 'assignDriver']);
Route::delete('inventory-transfers/{inventoryTransfer}/driver', [InventoryTransferController::class, 'removeDriver']);
Route::get('inventory-transfers/{inventoryTransfer}/checklist/{stage}', [InventoryTransferController::class, 'showChecklist'])
    ->where('stage', 'preparation|reception');
Route::post('inventory-transfers/{inventoryTransfer}/checklist/{stage}/items/{itemId}/check', [InventoryTransferController::class, 'checkChecklistItem'])
    ->where('stage', 'preparation|reception');

// FASE T2: guia de traslado (PDF + HTML).
// FIX: Route::bind() fuerza el binding explicito porque Laravel implicit
// binding en este controller devolvia un modelo vacio (Container cachea
// una instancia vacia de InventoryTransfer en algun lado).
Route::bind('inventoryTransferGuide', function (string $value): ?InventoryTransfer {
    return InventoryTransfer::query()->whereKey((int) $value)->first();
});
Route::get('inventory-transfers/{inventoryTransferGuide}/guide.pdf', [InventoryTransferGuideController::class, 'pdf']);
Route::get('inventory-transfers/{inventoryTransferGuide}/guide.html', [InventoryTransferGuideController::class, 'html']);

Route::apiResource('inventory-transfers', InventoryTransferController::class)
    ->parameters(['inventory-transfers' => 'inventoryTransfer'])
    ->only(['index', 'store', 'show']);
