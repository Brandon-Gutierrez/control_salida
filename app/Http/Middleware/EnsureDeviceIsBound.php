<?php

namespace App\Http\Middleware;

use App\Services\Auth\DeviceBindingService;
use App\Support\ClientPlatform;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cada petición autenticada debe venir del dispositivo vinculado a la cuenta
 * en la aplicación con la que inició sesión (web o mobile), y el rol debe
 * seguir teniendo acceso a esa aplicación.
 */
class EnsureDeviceIsBound
{
    public function __construct(private DeviceBindingService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $platform = $request->session()->get(ClientPlatform::SESSION_KEY);

        // Sesiones creadas antes de identificar la aplicación.
        if (! ClientPlatform::isValid($platform)) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'SESSION_PLATFORM_MISSING',
                'message' => 'Su sesión anterior ya no es válida. Inicie sesión nuevamente.',
            ], 401);
        }

        if (! ClientPlatform::allows($platform, $user->role?->name)) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'PLATFORM_NOT_ALLOWED',
                'message' => ClientPlatform::roleNotAllowedMessage($platform, $user->role?->name),
            ], 403);
        }

        if (! $this->devices->matches($user, $platform, $request->header('DeviceId'))) {
            return response()->json([
                'status' => 'ERROR',
                'code' => 'DEVICE_NOT_AUTHORIZED',
                'message' => ClientPlatform::deviceNotAuthorizedMessage($platform),
            ], 401);
        }

        return $next($request);
    }
}
