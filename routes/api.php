<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QrController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\PremiseController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/allowLogin', [UserController::class, 'login']);
Route::post('/login/admin', [UserController::class, 'loginAdmin']);

Route::post('/status', [QrController::class, 'fetchUserStatusBeforeQr']);
Route::get('/allowGet', [UserController::class, 'getUserData']);

Route::get('/generate-qr', [QrController::class, 'generateDynamicQr']);

Route::post('/confirmLeave', [LeaveController::class, 'confirmLeave']);

Route::get('/reasons', [LeaveController::class, 'getReasonsOfPremise']);

ROUTE::get('/fetchReasons', [LeaveController::class, 'fetchReasons']);

//Route::get('/reasons', [PremiseController::class, 'getReasons']);

    // Gestión de predioss
    Route::get('/premises', [PremiseController::class, 'index']);
    Route::post('/premises', [PremiseController::class, 'store']);
    Route::put('/premises/{id}/reasons', [PremiseController::class, 'updateReasons']);