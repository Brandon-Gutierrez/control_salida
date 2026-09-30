<?php
namespace App\Http\Controllers;

use App\Models\UserActiveSession;
use App\Repositories\UserActiveSessionRepository;
use App\Services\DeviceBindingService;
use App\Services\ThirdPartyService;
use App\Repositories\UserRepository;
use App\Support\ClientPlatform;

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
        UserActiveSessionRepository $userActiveSessionRepository,
        DeviceBindingService $deviceBindingService): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Cada aplicación se identifica (web / mobile) y envía el identificador
        // de su dispositivo: el acceso y la vinculación dependen de ambos.
        $platform = strtolower((string) $request->header(ClientPlatform::HEADER));
        if (!ClientPlatform::isValid($platform)) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'CLIENT_PLATFORM_REQUIRED',
                'message' => 'La aplicación no se identificó correctamente. Actualícela e intente de nuevo.',
            ], 422);
        }

        $deviceId = $request->header('DeviceId');
        if (!DeviceBindingService::isValidDeviceId($deviceId)) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'DEVICE_ID_REQUIRED',
                'message' => 'No se pudo identificar este dispositivo. Actualice la aplicación e intente de nuevo.',
            ], 422);
        }

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

        // web: ADMIN y MANAGE_PREMISE. mobile: ADMIN y EMPLOYEE.
        $roleName = $user->role?->name;
        if (!ClientPlatform::allows($platform, $roleName)) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'PLATFORM_NOT_ALLOWED',
                'message' => ClientPlatform::roleNotAllowedMessage($platform, $roleName),
            ], 403);
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

        // Un único dispositivo por cuenta y aplicación: el primero que inicia
        // sesión queda vinculado; para cambiarlo, administración / TI debe
        // desvincular el anterior.
        if (!$deviceBindingService->bindOrVerify($user, $platform, $deviceId)) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'DEVICE_NOT_AUTHORIZED',
                'message' => ClientPlatform::deviceNotAuthorizedMessage($platform),
            ], 403);
        }

        //CREAR SESION LARAVEL
        Auth::login($user);

        //REGENERAR LA SESION
        $request->session()->regenerate();
        $request->session()->put(ClientPlatform::SESSION_KEY, $platform);

        //REGISTRAR LA SESION
        $sessionId = $request->session()->getId();
        $userActiveSessionRepository->createSession(
            $user->user_id,
            $sessionId,
            $platform,
            (string) $request->header('User-Agent'),
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        // Una sola sesión activa por aplicación: la nueva reemplaza a la anterior
        // (p. ej. si se borraron las cookies en el mismo dispositivo).
        $userActiveSessionRepository->keepOnlySession($user->user_id, $platform, $sessionId);

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
