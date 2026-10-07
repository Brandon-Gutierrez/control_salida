<?php

namespace App\Http\Middleware;

use App\Support\ClientPlatform;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restringe una ruta a las aplicaciones indicadas (p. ej. check.platform:web). */
class CheckPlatform
{
    // Procesa la solicitud.
    public function handle(Request $request, Closure $next, string ...$platforms): Response
    {
        if (!in_array($request->session()->get(ClientPlatform::SESSION_KEY), $platforms, true)) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'PLATFORM_NOT_ALLOWED',
                'message' => 'Esta función no está disponible desde esta aplicación.',
            ], 403);
        }

        return $next($request);
    }
}
