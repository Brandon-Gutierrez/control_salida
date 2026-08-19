<?php
use App\Http\Controllers\QrController;

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/generate-qr', [QrController::class, 'generateDinamicQr']);
Route::post('/status', [QrController::class, 'getStatus']);
