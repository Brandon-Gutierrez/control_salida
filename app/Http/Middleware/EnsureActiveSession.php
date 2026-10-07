<?php

namespace App\Http\Middleware;

use App\Repositories\UserActiveSessionRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** La sesión de la petición debe seguir registrada como activa para el usuario. */
class EnsureActiveSession
{
    public function __construct(private UserActiveSessionRepository $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'No autenticado.',
            ], 401);
        }

        // Un usuario (p. ej. un ADMIN) puede tener varias sesiones activas a la
        // vez, así que se valida por existencia de ESTA sesión, no por una única
        // fila comparada contra todas.
        $activeSession = $this->sessions->find($user->user_id, $request->session()->getId());

        if (! $activeSession) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'La sesión fue cerrada porque se alcanzó el máximo de dispositivos conectados o se inició sesión desde otro lugar.',
            ], 401);
        }

        // Marca esta sesión como la más recientemente usada, para que al
        // superar el límite de sesiones se cierren primero las más inactivas.
        $activeSession->touch();

        return $next($request);
    }
}
