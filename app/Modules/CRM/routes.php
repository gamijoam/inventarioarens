<?php

use App\Modules\CRM\Controllers\CrmApiTokenController;
use App\Modules\CRM\Controllers\CrmIntegrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.auth', 'tenant'])
    ->prefix('crm/integration-tokens')
    ->group(function (): void {
        Route::get('/', [CrmApiTokenController::class, 'index']);
        Route::post('/', [CrmApiTokenController::class, 'store']);
        Route::delete('/{tokenId}', [CrmApiTokenController::class, 'destroy']);
        Route::post('/{tokenId}/rotate', [CrmApiTokenController::class, 'rotate']);
    });

Route::prefix('v1/integrations/crm')
    ->middleware(['crm.auth', 'throttle:crm'])
    ->group(function (): void {
        Route::get('branches', [CrmIntegrationController::class, 'branches'])
            ->middleware('crm.scope:branches.read');
        Route::get('warehouses', [CrmIntegrationController::class, 'warehouses'])
            ->middleware('crm.scope:branches.read');
        Route::get('products', [CrmIntegrationController::class, 'products'])
            ->middleware('crm.scope:catalog.read');
        Route::get('products/{sku}', [CrmIntegrationController::class, 'product'])
            ->middleware('crm.scope:catalog.read');
        Route::get('inventory/availability', [CrmIntegrationController::class, 'availability'])
            ->middleware('crm.scope:inventory.read');
    });
