<?php

use App\Modules\Quotations\Controllers\QuotationController;
use Illuminate\Support\Facades\Route;

Route::apiResource('quotations', QuotationController::class);

Route::post('quotations/{quotation}/convert', [QuotationController::class, 'convert']);
Route::get('quotations/{quotation}/pdf', [QuotationController::class, 'pdf']);
Route::get('quotations/{quotation}/pdf.html', [QuotationController::class, 'pdfHtml']);
