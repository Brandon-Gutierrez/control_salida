<?php

namespace App\Http\Middleware;

use App\Models\UserActiveSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckActiveSession
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'No autenticado.'
            ], 401);
        }

        $activeSession = UserActiveSession::where(
            'user_id',
            $user->user_id
        )->first();

        if (!$activeSession) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'La sesión ya no está activa.'
            ], 401);
        }

        if (
            $activeSession->session_id !==
            $request->session()->getId()
        ) {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'La sesión fue cerrada porque se inició sesión desde otro dispositivo.'
            ], 401);
        }

        return $next($request);
    }
}