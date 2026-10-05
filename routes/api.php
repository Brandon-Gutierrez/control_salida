<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\PremiseController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Models\Role;
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

Route::middleware(['auth:sanctum', 'check.active.session', 'check.deviceid'])->group(function () {

    // Sesión
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // App móvil: empleados y administradores registran sus salidas.
    // La cuenta del responsable no puede acceder a las funciones de empleados.
    Route::middleware(['check.platform:mobile', 'check.authorization:EMPLOYEE,ADMIN'])->group(function () {
        Route::get('/me/leave-status', [UserController::class, 'leaveStatus'])->name('me.leave-status');
        Route::get('/me/leave-stats', [UserController::class, 'leaveStats'])->name('me.leave-stats');
        Route::post('/qr/scan', [QrController::class, 'scan'])
            ->middleware('check.premise.location')->name('qr.scan');
        Route::get('/premises/{premise:name}/reasons', [LeaveController::class, 'premiseReasons'])->name('premises.reasons');
        Route::post('/leaves', [LeaveController::class, 'store'])
            ->middleware('check.premise.location')->name('leaves.store');
    });

    Route::prefix('manager')->name('manager.')
        ->middleware(['check.platform:web', 'check.authorization:' . Role::MANAGE_PREMISE])
        ->group(function () {
            Route::post('/qr-token', [QrController::class, 'storeForResponsible'])->name('qr-token.store');
        });

    // Administrador (app web)
    Route::prefix('admin')
        ->name('admin.')
        ->middleware(['check.platform:web', 'check.authorization:ADMIN'])
        ->group(function () {
            Route::get('/premises', [PremiseController::class, 'index'])->name('premises.index');
            Route::post('/premises', [PremiseController::class, 'store'])->name('premises.store');
            Route::put('/premises/{premise}', [PremiseController::class, 'update'])->name('premises.update');
            Route::put('/premises/{premise}/reasons', [PremiseController::class, 'updateReasons'])->name('premises.reasons.update');
            Route::post('/premises/{premise}/qr-tokens', [QrController::class, 'store'])->name('premises.qr-tokens.store');

            Route::get('/reasons', [PremiseController::class, 'reasons'])->name('reasons.index');
            Route::post('/reasons/sync', [LeaveController::class, 'syncReasons'])->name('reasons.sync');

            Route::get('/roles', [UserController::class, 'roles'])->name('roles.index');
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::put('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role.update');
            Route::put('/users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password.update');
            Route::put('/users/{user}/premise', [UserController::class, 'assignPremise'])->name('users.premise.update');
            Route::post('/users/{user}/device/reset', [UserController::class, 'resetUserDevice'])->name('users.device.reset');
            Route::post('/users/premise-managers', [UserController::class, 'createPremiseManager'])->name('users.premise-managers.store');

            Route::get('/settings/qr', [SettingsController::class, 'show'])->name('settings.qr.show');
            Route::put('/settings/qr', [SettingsController::class, 'update'])->name('settings.qr.update');
            Route::get('/settings/leave-limits', [SettingsController::class, 'showLeaveLimits'])->name('settings.leave-limits.show');
            Route::put('/settings/leave-limits', [SettingsController::class, 'updateLeaveLimits'])->name('settings.leave-limits.update');
        });
});
