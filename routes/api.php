<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QrController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LeaveController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::post('/status', [QrController::class, 'getStatus']);

Route::post('/allowLogin', [UserController::class, 'login']);
Route::post('/allowGet', [UserController::class, 'getUserStatus']);

Route::get('/generate-qr', [QrController::class, 'generateDynamicQr']);
Route::post('/confirmLeave', [LeaveController::class, 'confirmLeave']);
Route::get('/leaves', [LeaveController::class, 'getLeavesOfPremise']);