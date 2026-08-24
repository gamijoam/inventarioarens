<?php

use App\Modules\InventoryTransferRequests\Controllers\IntercompanyNotificationController;
use App\Modules\InventoryTransferRequests\Controllers\InventoryTransferRequestController;
use Illuminate\Support\Facades\Route;

Route::get('inventory-transfer-requests', [InventoryTransferRequestController::class, 'index']);
Route::post('inventory-transfer-requests', [InventoryTransferRequestController::class, 'store'])->middleware('idempotency');
Route::get('inventory-transfer-requests/{inventoryTransferRequest}', [InventoryTransferRequestController::class, 'show']);
Route::post('inventory-transfer-requests/{inventoryTransferRequest}/accept', [InventoryTransferRequestController::class, 'accept'])->middleware('idempotency');
Route::post('inventory-transfer-requests/{inventoryTransferRequest}/reject', [InventoryTransferRequestController::class, 'reject'])->middleware('idempotency');
Route::post('inventory-transfer-requests/{inventoryTransferRequest}/cancel', [InventoryTransferRequestController::class, 'cancel'])->middleware('idempotency');
Route::post('inventory-transfer-requests/{inventoryTransferRequest}/guide/prepare', [InventoryTransferRequestController::class, 'prepare'])->middleware('idempotency')->name('inventory-transfer-requests.guide.prepare');
Route::post('inventory-transfer-requests/{inventoryTransferRequest}/guide/dispatch', [InventoryTransferRequestController::class, 'dispatch'])->middleware('idempotency');
Route::post('inventory-transfer-requests/{inventoryTransferRequest}/guide/deliver', [InventoryTransferRequestController::class, 'deliver'])->middleware('idempotency');
Route::post('inventory-transfer-requests/{inventoryTransferRequest}/guide/receive', [InventoryTransferRequestController::class, 'receive'])->middleware('idempotency')->name('inventory-transfer-requests.guide.receive');

Route::get('inventory-transfer-notifications', [IntercompanyNotificationController::class, 'index']);
Route::get('inventory-transfer-notifications/unread-count', [IntercompanyNotificationController::class, 'unreadCount']);
Route::post('inventory-transfer-notifications/read-all', [IntercompanyNotificationController::class, 'markAllRead'])->middleware('idempotency');
Route::post('inventory-transfer-notifications/{notification}/read', [IntercompanyNotificationController::class, 'markRead'])->middleware('idempotency');
