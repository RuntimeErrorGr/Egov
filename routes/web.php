<?php

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\CrawlerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::controller(CrawlerController::class)->group(function () {
    Route::get('/crawl', 'index');
    Route::post('/crawl', 'crawl')->name('crawl');
    Route::get('/crawl/download', 'download');
});

Route::controller(PaymentController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/download', 'download');
    Route::get('/analytics', 'analytics');
});
