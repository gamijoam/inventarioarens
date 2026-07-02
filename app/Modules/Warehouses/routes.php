<?php

use App\Modules\Warehouses\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::apiResource('warehouses', WarehouseController::class);
