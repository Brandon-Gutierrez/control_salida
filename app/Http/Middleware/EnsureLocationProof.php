<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * La petición trae una prueba de ubicación válida: coordenadas, precisión,
 * captura reciente y sin señales de falsificación (GPS simulado o VPN).
 *
 * No decide si la persona está en un predio: eso depende del predio del QR o
 * del comprobante, que se conoce después, y lo comprueba
 * {@see \App\Services\Location\PremiseLocationService}. La prueba validada
 * queda en el atributo `location` de la petición.
 *
 * El dispositivo informa por sí mismo si detectó GPS simulado o VPN: una app
 * modificada podría falsear esos indicadores, así que son una señal de apoyo y
 * no una prueba independiente de ubicación.
 */
class EnsureLocationProof
{
    public function handle(Request $request, Closure $next): Response
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'location_timestamp' => ['required', 'date'],
            'is_mocked' => ['required', 'boolean'],
            'vpn_detected' => ['required', 'boolean'],
        ]);

        if ($request->boolean('is_mocked') || $request->boolean('vpn_detected')) {
            Log::warning('Ubicación rechazada por posible falsificación.', [
                'user_id' => $request->user()?->user_id,
                'is_mocked' => $request->boolean('is_mocked'),
                'vpn_detected' => $request->boolean('vpn_detected'),
            ]);

            return response()->json([
                'status' => 1,
                'code' => 'LOCATION_SPOOFING_DETECTED',
                'message' => 'No se puede validar su ubicación. Desactive la ubicación simulada (GPS falso) y las conexiones VPN e intente nuevamente.',
            ], 403);
        }

        $capturedAt = strtotime($data['location_timestamp']);
        $now = now()->timestamp;
        if ($capturedAt === false
            || $capturedAt < $now - (int) config('geofence.max_age_seconds')
            || $capturedAt > $now + (int) config('geofence.max_future_seconds')) {
            return response()->json([
                'status' => 1,
                'message' => 'La ubicación está vencida o tiene una fecha inválida. Actualice su ubicación e intente nuevamente.',
            ], 403);
        }

        $request->attributes->set('location', [
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'accuracy_m' => (float) $data['accuracy_m'],
        ]);

        return $next($request);
    }
}
