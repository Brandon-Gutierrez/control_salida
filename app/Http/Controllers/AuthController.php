<?php
namespace App\Http\Controllers;

use App\Models\UserActiveSession;
use App\Repositories\UserActiveSessionRepository;
use App\Services\ThirdPartyService;
use App\Repositories\UserRepository;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request,
        ThirdPartyService $thirdPartyService,
        UserRepository $userRepository,
        UserActiveSessionRepository $userActiveSessionRepository): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Las cuentas responsables de predio usan credenciales locales.
        $localUser = User::where('username', $data['username'])->first();
        if ($localUser) {
            if (!$localUser->isPremiseManager()
                || !Hash::check($data['password'], $localUser->password ?? '')) {
                return response()->json([
                    'status' => 'ERROR',
                    'message' => 'Credenciales inválidas.'
                ], 401);
            }

            $user = $localUser;
        } else {
            //AUTENTICACION CON EL SERVICIO EXTERNO
        $userData = $thirdPartyService->authenticate(
            $data['username'],
            $data['password']
        );

        if (!$userData) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'Credenciales inválidas.'
            ], 401);
        }

        //REGISTRAR EN LA BASE DE DATOS
        $user = $userRepository->registerUser(
            $userData['external_identifier'],
            $userData['name'],
            $userData['item']
        );
        }

        // Un responsable sin predio no tiene nada que mostrar y, como no puede
        // cerrar sesión, no se le abre sesión hasta que administración lo asigne.
        if ($user->isPremiseManager() && !$user->premise_id) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'PREMISE_NOT_ASSIGNED',
                'message' => 'La cuenta no tiene un predio asignado. Contacte a administración.',
            ], 403);
        }

        // El bloqueo de dispositivo aplica a EMPLOYEE (app móvil); los roles
        // administrativos y MANAGE_PREMISE acceden desde la versión web.
        if (strtoupper($user->role?->name ?? '') === 'EMPLOYEE') {
            $deviceId = $request->header('DeviceId');
            if (!is_string($deviceId) || strlen($deviceId) < 16 || strlen($deviceId) > 255) {
                return response()->json([
                    'status' => 'ERROR',
                    'message' => 'Falta un identificador de dispositivo válido en el encabezado DeviceId.',
                ], 422);
            }

            // El primer acceso vincula la cuenta al dispositivo. La actualización
            // condicional evita que dos primeros accesos vinculen dos dispositivos.
            $deviceHash = hash('sha256', $deviceId);
            if (!$user->device_id) {
                User::where('user_id', $user->user_id)
                    ->whereNull('device_id')
                    ->update([
                        'device_id' => $deviceHash,
                        'device_bound_at' => now(),
                    ]);
                $user->refresh();
            }

            if (!$user->device_id || !hash_equals($user->device_id, $deviceHash)) {
                return response()->json([
                    'status' => 'ERROR',
                    'message' => 'La cuenta ya está vinculada a otro dispositivo. Contacte a Recursos Humanos para solicitar el cambio.',
                    'code' => 'DEVICE_CHANGE_REQUIRES_HR',
                ], 403);
            }
        }

        //CREAR SESION LARAVEL
        Auth::login($user);

        //REGENERAR LA SESION
        $request->session()->regenerate();

        //REGISTRAR LA SESION
        $userActiveSessionRepository->createSession(
            $user->user_id,
            $request->session()->getId(),
            (string) $request->header('User-Agent'),
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        //LIMITAR SESIONES CONCURRENTES: 1 para empleados, hasta 5 para administradores.
        //Se conservan las usadas más recientemente y se cierran las demás.
        $maxSessions = strtoupper($user->role?->name ?? '') === 'ADMIN' ? 5 : 1;
        $userActiveSessionRepository->enforceSessionLimit($user->user_id, $maxSessions);

        return response()->json([
            'status' => 'SUCCESS',
            'message' => 'Inicio de sesión exitoso.',
            'user' => $user->load(['role', 'premise']),
        ], 200);
    }

    //Usuario autenticado de la sesion actual
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'SUCCESS',
            'user' => $request->user()->load(['role', 'premise']),
        ], 200);
    }

    //Cerrar la sesion actual (solo el dispositivo actual; no afecta otras sesiones del admin)
    public function logout(Request $request): JsonResponse
    {
        // El responsable de predio deja la pantalla de QR abierta de forma
        // permanente: su sesión solo la puede cerrar administración.
        if ($request->user()->isPremiseManager()) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'LOGOUT_NOT_ALLOWED',
                'message' => 'El responsable de predio no puede cerrar sesión desde esta cuenta.'
            ], 403);
        }
        UserActiveSession::where('user_id', $request->user()->user_id)
            ->where('session_id', $request->session()->getId())
            ->delete();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'status' => 'SUCCESS',
            'message' => 'Sesión cerrada.',
        ], 200);
    }
}
