<?php

use App\Modules\DataImport\Controllers\DataImportMasterController;
use App\Modules\DataImport\Controllers\DataImportSessionController;
use App\Modules\DataImport\Controllers\DataImportTemplateController;
use App\Modules\DataImport\Controllers\DataImportWizardController;
use App\Modules\Tenancy\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.auth', 'tenant'])
    ->prefix('import')
    ->name('import.')
    ->group(function () {
        Route::get('sessions', [DataImportSessionController::class, 'index'])->name('sessions.index');
        Route::post('sessions', [DataImportSessionController::class, 'store'])->name('sessions.store');
        Route::get('sessions/{dataImport}', [DataImportSessionController::class, 'show'])->name('sessions.show');
        Route::delete('sessions/{dataImport}', [DataImportSessionController::class, 'destroy'])->name('sessions.destroy');
        Route::get('sessions/{dataImport}/entities/{entity}/rows', [DataImportSessionController::class, 'entityRows'])
            ->name('sessions.entities.rows');
        Route::get('sessions/{dataImport}/report', [DataImportWizardController::class, 'report'])->name('sessions.report');
        Route::post('sessions/{dataImport}/entities/{entity}/upload', [DataImportWizardController::class, 'upload'])->name('sessions.entities.upload');
        Route::post('sessions/{dataImport}/entities/{entity}/run', [DataImportWizardController::class, 'run'])->name('sessions.entities.run');
        Route::get('templates/{entity}', [DataImportTemplateController::class, 'download'])->name('templates.download');
    });

/**
 * Master routes: un platform admin puede crear/correr imports en nombre
 * de cualquier tenant, sin necesidad de membership. Pensado para operaciones
 * de soporte / onboarding corporativo.
 */
Route::middleware(['api.auth', EnsurePlatformAdmin::class])
    ->prefix('master/import')
    ->name('master.import.')
    ->group(function () {
        Route::get('tenants/{tenant}/sessions', [DataImportMasterController::class, 'indexSessions']);
        Route::post('tenants/{tenant}/sessions', [DataImportMasterController::class, 'storeSession']);
        Route::post('tenants/{tenant}/sessions/{dataImport}/entities/{entity}/upload', [DataImportMasterController::class, 'uploadFile']);
        Route::post('tenants/{tenant}/sessions/{dataImport}/entities/{entity}/run', [DataImportMasterController::class, 'runEntity']);
        Route::get('tenants/{tenant}/sessions/{dataImport}/report', [DataImportMasterController::class, 'report']);
        Route::get('tenants/{tenant}/templates/{entity}', [DataImportMasterController::class, 'template']);
    });
