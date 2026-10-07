<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveLimitController;
use App\Http\Controllers\LeaveStatsController;
use App\Http\Controllers\LeaveStatusController;
use App\Http\Controllers\PremiseController;
use App\Http\Controllers\PremiseManagerController;
use App\Http\Controllers\PremiseReasonController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\QrScanController;
use App\Http\Controllers\QrSettingsController;
use App\Http\Controllers\ReasonController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API (prefijo /api)
|--------------------------------------------------------------------------
| - Rutas públicas: salud y login.
| - Rutas autenticadas (sesión Sanctum + sesión única activa + dispositivo vinculado).
|   - Empleado (app móvil): estado, escaneo de QR, motivos y registro de salida.
|   - Responsable de predio (app web): QR de su predio.
|   - Administrador (app web): /api/admin/*
*/

Route::get('/health', fn () => response()->json(['status' => 'OK']))->name('health');

Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware(['auth:sanctum', 'active.session', 'device.bound'])->group(function () {

    // Sesión
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // App móvil: empleados y administradores registran sus salidas.
    // La cuenta del responsable no puede acceder a las funciones de empleados.
    Route::middleware(['platform:mobile', 'role:'.Role::EMPLOYEE.','.Role::ADMIN])->group(function () {
        Route::get('/me/leave-status', LeaveStatusController::class)->name('me.leave-status');
        Route::get('/me/leave-stats', LeaveStatsController::class)->name('me.leave-stats');
        Route::post('/qr/scan', QrScanController::class)
            ->middleware('premise.location')->name('qr.scan');
        Route::get('/premises/{premise:name}/reasons', [PremiseReasonController::class, 'index'])->name('premises.reasons');
        Route::post('/leaves', [LeaveController::class, 'store'])
            ->middleware('premise.location')->name('leaves.store');
    });

    // Responsable de predio (app web)
    Route::prefix('manager')->name('manager.')
        ->middleware(['platform:web', 'role:'.Role::MANAGE_PREMISE])
        ->group(function () {
            Route::post('/qr-token', [QrController::class, 'storeForManager'])->name('qr-token.store');
        });

    // Administrador (app web)
    Route::prefix('admin')->name('admin.')
        ->middleware(['platform:web', 'role:'.Role::ADMIN])
        ->group(function () {
            Route::get('/premises', [PremiseController::class, 'index'])->name('premises.index');
            Route::post('/premises', [PremiseController::class, 'store'])->name('premises.store');
            Route::put('/premises/{premise}', [PremiseController::class, 'update'])->name('premises.update');
            Route::put('/premises/{premise}/reasons', [PremiseReasonController::class, 'update'])->name('premises.reasons.update');
            Route::post('/premises/{premise}/qr-tokens', [QrController::class, 'store'])->name('premises.qr-tokens.store');

            Route::get('/reasons', [ReasonController::class, 'index'])->name('reasons.index');
            Route::post('/reasons/sync', [ReasonController::class, 'sync'])->name('reasons.sync');

            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::put('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role.update');
            Route::put('/users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password.update');
            Route::put('/users/{user}/premise', [UserController::class, 'assignPremise'])->name('users.premise.update');
            Route::post('/users/{user}/device/reset', [UserController::class, 'resetDevice'])->name('users.device.reset');
            Route::post('/users/premise-managers', [PremiseManagerController::class, 'store'])->name('users.premise-managers.store');

            Route::get('/settings/qr', [QrSettingsController::class, 'show'])->name('settings.qr.show');
            Route::put('/settings/qr', [QrSettingsController::class, 'update'])->name('settings.qr.update');
            Route::get('/settings/leave-limits', [LeaveLimitController::class, 'show'])->name('settings.leave-limits.show');
            Route::put('/settings/leave-limits', [LeaveLimitController::class, 'update'])->name('settings.leave-limits.update');
        });
});
