<?php

use App\Modules\ReportsV2\Controllers\ReportV2Controller;
use Illuminate\Support\Facades\Route;

Route::get('reports/v2', [ReportV2Controller::class, 'catalog']);
Route::get('reports/v2/{report}/export', [ReportV2Controller::class, 'export']);
Route::get('reports/v2/{report}', [ReportV2Controller::class, 'report']);
