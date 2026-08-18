<?php

use App\Modules\ReportsV2\Controllers\ReportV2Controller;
use Illuminate\Support\Facades\Route;

Route::get('reports/v2/{report}', [ReportV2Controller::class, 'report']);
