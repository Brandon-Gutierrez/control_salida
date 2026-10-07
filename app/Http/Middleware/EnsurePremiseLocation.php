<?php

namespace App\Http\Middleware;

use App\Models\Premise;
use App\Support\Geo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * La persona debe estar a menos de 50 m de algún predio, con una ubicación
 * reciente y sin señales de falsificación (GPS simulado o VPN).
 */
class EnsurePremiseLocation
{
    private const MAX_RADIUS_METERS = 50;

    private const MAX_AGE_SECONDS = 120;

    private const MAX_FUTURE_SECONDS = 15;

    public function handle(Request $request, Closure $next): Response
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_RADIUS_METERS],
            'location_timestamp' => ['required', 'date'],
            'is_mocked' => ['required', 'boolean'],
            'vpn_detected' => ['required', 'boolean'],
        ]);

        // Señales de falsificación reportadas por el dispositivo (ubicación
        // simulada del sistema o conexión por VPN/proxy).
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
            || $capturedAt < $now - self::MAX_AGE_SECONDS
            || $capturedAt > $now + self::MAX_FUTURE_SECONDS) {
            return response()->json([
                'status' => 1,
                'message' => 'La ubicación está vencida o tiene una fecha inválida. Actualice su ubicación e intente nuevamente.',
            ], 403);
        }

        $premises = Premise::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['latitude', 'longitude']);

        foreach ($premises as $premise) {
            $distance = Geo::distanceInMeters(
                (float) $data['latitude'],
                (float) $data['longitude'],
                (float) $premise->latitude,
                (float) $premise->longitude,
            );

            // La precisión reportada se resta del radio para no aceptar un punto incierto.
            if ($distance + (float) $data['accuracy_m'] <= self::MAX_RADIUS_METERS) {
                return $next($request);
            }
        }

        return response()->json([
            'status' => 1,
            'message' => $premises->isEmpty()
                ? 'No hay predios con ubicación configurada. Contacte a administración.'
                : 'Debe encontrarse dentro de '.self::MAX_RADIUS_METERS.' metros de un predio para realizar esta acción.',
        ], 403);
    }
}
