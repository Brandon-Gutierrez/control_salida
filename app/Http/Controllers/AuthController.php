<?php
namespace App\Http\Controllers;

use App\Models\UserActiveSession;
use App\Repositories\UserActiveSessionRepository;
use App\Services\ThirdPartyService;
use App\Repositories\UserRepository;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        //ELIMINAR SESION ANTERIOR SI ES EMPLOYEE
        if ($user->role && $user->role->name === 'EMPLOYEE'){
            UserActiveSession::where(
                'user_id',
                $user->user_id
            )->delete();
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

        return response()->json([
            'status' => 'SUCCESS',
            'message' => 'Inicio de sesión exitoso.',
            'user' => $user->load('role'),
        ], 200);
    }

    //Usuario autenticado de la sesion actual
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'SUCCESS',
            'user' => $request->user()->load('role'),
        ], 200);
    }

    //Cerrar la sesion actual
    public function logout(Request $request): JsonResponse
    {
        UserActiveSession::where('user_id', $request->user()->user_id)->delete();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'status' => 'SUCCESS',
            'message' => 'Sesión cerrada.',
        ], 200);
    }
}
