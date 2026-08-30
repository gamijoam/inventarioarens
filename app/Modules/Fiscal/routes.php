<?php

use App\Modules\Fiscal\Controllers\FiscalDocumentController;
use App\Modules\Fiscal\Controllers\FiscalIdentityController;
use App\Modules\Fiscal\Controllers\FiscalTaxRateController;
use Illuminate\Support\Facades\Route;

Route::get('fiscal/identity', [FiscalIdentityController::class, 'show']);
Route::patch('fiscal/identity', [FiscalIdentityController::class, 'update']);
Route::get('fiscal/identity/branches/{branch}', [FiscalIdentityController::class, 'showBranch']);
Route::patch('fiscal/identity/branches/{branch}', [FiscalIdentityController::class, 'updateBranch']);

Route::post('fiscal/documents/previews', [FiscalDocumentController::class, 'preview'])
    ->middleware('idempotency');
Route::get('fiscal/documents', [FiscalDocumentController::class, 'index']);
Route::get('fiscal/documents/{fiscalDocument}', [FiscalDocumentController::class, 'show']);

Route::get('fiscal/tax-rates', [FiscalTaxRateController::class, 'index']);
Route::post('fiscal/tax-rates', [FiscalTaxRateController::class, 'store']);
Route::get('fiscal/tax-rates/{taxRate}', [FiscalTaxRateController::class, 'show']);
Route::patch('fiscal/tax-rates/{taxRate}', [FiscalTaxRateController::class, 'update']);
