<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAuthorization
{
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

        if (!in_array($user->role->name, $roles)) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'No tiene permisos para realizar esta acción.'
            ], 403);
        }

        return $next($request);
    }
}