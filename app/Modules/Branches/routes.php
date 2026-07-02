<?php

use App\Modules\Branches\Controllers\BranchController;
use Illuminate\Support\Facades\Route;

Route::apiResource('branches', BranchController::class);
