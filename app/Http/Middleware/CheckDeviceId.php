<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckDeviceId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // La vinculación persistente es para empleados de la aplicación móvil.
        // Admin y responsables de predio usan el panel web.
        if ($user && strtoupper($user->role?->name ?? '') !== 'EMPLOYEE') {
            return $next($request);
        }

        $deviceId = $request->header('DeviceId');

        if ($user && is_string($deviceId) && $deviceId !== '' && $user->device_id
            && hash_equals($user->device_id, hash('sha256', $deviceId))) {
            return $next($request);
        }

        return response()->json([
            'status' => 'ERROR',
            'message' => 'Este dispositivo no está autorizado para la cuenta. Contacte a Recursos Humanos para cambiarlo.',
        ], 403);
    }
}
