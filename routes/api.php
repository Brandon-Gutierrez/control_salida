<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\PremiseController;
use Illuminate\Session\Middleware\StartSession;

Route::get('/test', function () {
    return response()->json([
        'message' => 'API funcionando',
    ]);
});
Route::post('/login', [AuthController::class, 'login']);

Route::middleware([
    'auth:sanctum',
    'check.active.session',
    //'check.authorization:ADMIN,EMPLOYEE'
])->group(function () {
    Route::get('/user', function (Request $request) {
        return response()->json([
            'user' => $request->user(),
        ]);
    });
});


Route::middleware([
    'auth:sanctum',
    'check.active.session',
    'check.authorization:ADMIN',
])->get('/test-admin', function (Request $request) {

    return response()->json([
        'status' => 'SUCCESS',
        'message' => 'Acceso autorizado como ADMIN.',
        'user' => $request->user()->load('role'),
    ]);

});


Route::post('/status', [QrController::class, 'fetchUserStatusBeforeQr']);
Route::get('/allowGet', [UserController::class, 'getUserData']);

Route::get('/generate-qr', [QrController::class, 'generateDynamicQr']);

Route::post('/confirmLeave', [LeaveController::class, 'confirmLeave']);

Route::get('/reasons', [LeaveController::class, 'getReasonsOfPremise']);

ROUTE::get('/updateReasons', [LeaveController::class, 'updateReasons']); // $namePremise=

Route::get('/allReasons', [PremiseController::class, 'getAllReasons']);
Route::get('/premisesWithReasons', [PremiseController::class, 'index']);


Route::post('/createPremises', [PremiseController::class, 'store']);
Route::put('/premises/{id}/reasons', [PremiseController::class, 'updateReasons']);