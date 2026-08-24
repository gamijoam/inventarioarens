<?php

use App\Modules\InventoryTransfers\Controllers\InventoryTransferController;
use App\Modules\InventoryTransfers\Controllers\InventoryTransferGuideController;
use Illuminate\Support\Facades\Route;

Route::post('inventory-transfers/{inventoryTransfer}/prepare', [InventoryTransferController::class, 'prepare'])
    ->middleware('idempotency');
Route::post('inventory-transfers/{inventoryTransfer}/dispatch', [InventoryTransferController::class, 'dispatch'])
    ->middleware('idempotency');
Route::post('inventory-transfers/{inventoryTransfer}/receive', [InventoryTransferController::class, 'receive'])
    ->middleware('idempotency');
Route::post('inventory-transfers/{inventoryTransfer}/cancel', [InventoryTransferController::class, 'cancel'])
    ->middleware('idempotency');
Route::post('inventory-transfers/{inventoryTransfer}/resolve-differences', [InventoryTransferController::class, 'resolveDifferences'])
    ->middleware('idempotency');

// FASE T1: driver + checklist interactivo.
Route::put('inventory-transfers/{inventoryTransfer}/driver', [InventoryTransferController::class, 'assignDriver'])
    ->middleware('idempotency');
Route::delete('inventory-transfers/{inventoryTransfer}/driver', [InventoryTransferController::class, 'removeDriver'])
    ->middleware('idempotency');
Route::get('inventory-transfers/{inventoryTransfer}/checklist/{stage}', [InventoryTransferController::class, 'showChecklist'])
    ->where('stage', 'preparation|reception');
Route::post('inventory-transfers/{inventoryTransfer}/checklist/{stage}/items/{itemId}/check', [InventoryTransferController::class, 'checkChecklistItem'])
    ->where('stage', 'preparation|reception');

// FASE T2: guia de traslado (PDF + HTML).
Route::get('inventory-transfers/{inventoryTransfer}/guide.pdf', [InventoryTransferGuideController::class, 'pdf']);
Route::get('inventory-transfers/{inventoryTransfer}/guide.html', [InventoryTransferGuideController::class, 'html']);

// FASE T2: timeline del traslado (eventos en orden cronologico).
Route::get('inventory-transfers/{inventoryTransfer}/timeline', [InventoryTransferController::class, 'timeline']);

Route::apiResource('inventory-transfers', InventoryTransferController::class)
    ->parameters(['inventory-transfers' => 'inventoryTransfer'])
    ->only(['index', 'show']);

Route::post('inventory-transfers', [InventoryTransferController::class, 'store'])
    ->middleware('idempotency');
