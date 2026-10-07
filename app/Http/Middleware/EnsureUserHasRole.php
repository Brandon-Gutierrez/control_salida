<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Valida los permisos del usuario.
class CheckAuthorization
{
    // Procesa la solicitud.
    public function handle(
        Request $request,
        Closure $next,
        ...$roles
    ): Response {

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'Usuario no autenticado.'
            ], 401);
        }

        if (!$user->role) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'El usuario no tiene un rol asignado.'
            ], 403);
        }

        $allowedRoles = array_map('strtoupper', $roles);
        if (!in_array(strtoupper($user->role->name), $allowedRoles, true)) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'No tiene permisos para realizar esta acción.'
            ], 403);
        }

        return $next($request);
    }
}
