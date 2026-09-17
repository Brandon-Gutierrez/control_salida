<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\PremiseController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (prefijo /api)
|--------------------------------------------------------------------------
| - Rutas públicas: salud y login.
| - Rutas autenticadas (sesión Sanctum + sesión única activa).
|   - Empleado (app móvil): estado, escaneo de QR, motivos y registro de salida.
|   - Administrador (app web): /api/admin/*
*/

Route::get('/health', fn () => response()->json(['status' => 'OK']))->name('health');

Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware(['auth:sanctum', 'check.active.session'])->group(function () {

    // Sesión
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // Empleado (app móvil)
    Route::get('/me/leave-status', [UserController::class, 'leaveStatus'])->name('me.leave-status');
    Route::post('/qr/scan', [QrController::class, 'scan'])->name('qr.scan');
    Route::get('/premises/{premise:name}/reasons', [LeaveController::class, 'premiseReasons'])->name('premises.reasons');
    Route::post('/leaves', [LeaveController::class, 'store'])->name('leaves.store');

    // Administrador (app web)
    Route::prefix('admin')
        ->name('admin.')
        ->middleware('check.authorization:ADMIN')
        ->group(function () {
            Route::get('/premises', [PremiseController::class, 'index'])->name('premises.index');
            Route::post('/premises', [PremiseController::class, 'store'])->name('premises.store');
            Route::put('/premises/{premise}/reasons', [PremiseController::class, 'updateReasons'])->name('premises.reasons.update');
            Route::post('/premises/{premise}/qr-tokens', [QrController::class, 'store'])->name('premises.qr-tokens.store');

            Route::get('/reasons', [PremiseController::class, 'reasons'])->name('reasons.index');
            Route::post('/reasons/sync', [LeaveController::class, 'syncReasons'])->name('reasons.sync');

            Route::get('/roles', [UserController::class, 'roles'])->name('roles.index');
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::put('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role.update');
        });
});
