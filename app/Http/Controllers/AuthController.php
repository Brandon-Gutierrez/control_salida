<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserActiveSession;
use App\Repositories\UserActiveSessionRepository;
use App\Services\ThirdPartyService;
use App\Repositories\UserRepository;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request, 
        ThirdPartyService $thirdPartyService,
        UserRepository $userRepository,
        UserActiveSessionRepository $userActiveSessionRepository)
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

        //ELIMINAR SESION ANTERIOR
        UserActiveSession::where(
            'user_id',
            $user->user_id
        )->delete();

        //CREAR SESION LARAVEL
        Auth::login($user);

        //REGENERAR LA SESION
        $request->session()->regenerate();

        //REGISTRAR LA SESION
        $userActiveSessionRepository->createSession(
            $user->user_id,
            $request->session()->getId(),
            $request->header('User-Agent'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'status' => 'SUCCESS',
            'message' => 'Inicio de sesión exitoso.',
            'user' => $user,
        ], 200);
    }
}