<?php

use App\Modules\Products\Controllers\BrandController;
use App\Modules\Products\Controllers\CategoryController;
use App\Modules\Products\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::get('brands', [BrandController::class, 'index']);
Route::post('brands', [BrandController::class, 'store']);
Route::get('brands/{brand}', [BrandController::class, 'show']);
Route::patch('brands/{brand}', [BrandController::class, 'update']);
Route::put('brands/{brand}', [BrandController::class, 'update']);
Route::delete('brands/{brand}', [BrandController::class, 'destroy']);

Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/tree', [CategoryController::class, 'tree']);
Route::post('categories', [CategoryController::class, 'store']);
Route::get('categories/{category}', [CategoryController::class, 'show']);
Route::patch('categories/{category}', [CategoryController::class, 'update']);
Route::put('categories/{category}', [CategoryController::class, 'update']);
Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

Route::get('tags', [TagController::class, 'index']);
Route::post('tags', [TagController::class, 'store']);
Route::get('tags/{tag}', [TagController::class, 'show']);
Route::patch('tags/{tag}', [TagController::class, 'update']);
Route::put('tags/{tag}', [TagController::class, 'update']);
Route::delete('tags/{tag}', [TagController::class, 'destroy']);
