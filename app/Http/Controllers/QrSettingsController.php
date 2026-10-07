<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\LeaveQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Gestiona la configuración.
class SettingsController extends Controller
{
    private const QR_TTL_KEY = 'qr_ttl_seconds';
    public const QR_TTL_DEFAULT = 300;
    private const QR_TTL_MIN = 30;
    private const QR_TTL_MAX = 3600;

    /** Tiempo de vida (segundos) que ve el usuario antes de que el QR expire. */
    public static function qrTtlSeconds(): int
    {
        return (int) Setting::get(self::QR_TTL_KEY, (string) self::QR_TTL_DEFAULT);
    }

    // Muestra la configuración.
    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'data' => [
                'qr_ttl_seconds' => self::qrTtlSeconds(),
                'qr_ttl_seconds_min' => self::QR_TTL_MIN,
                'qr_ttl_seconds_max' => self::QR_TTL_MAX,
            ],
        ], 200);
    }

    // Muestra los límites de salida.
    public function showLeaveLimits(): JsonResponse
    {
        return response()->json(['status' => 0, 'data' => LeaveQuotaService::policy()], 200);
    }

    /** Un solo límite de salidas para todas las personas. Vacío = sin tope. */
    public function updateLeaveLimits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'in:day,week,month'],
            'max_exits' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
            'max_exits_per_premise' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        LeaveQuotaService::savePolicy($data['period'], $data['max_exits'], $data['max_exits_per_premise']);

        return response()->json(['status' => 0, 'data' => LeaveQuotaService::policy()], 200);
    }

    // Actualiza el registro recibido.
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_ttl_seconds' => [
                'required',
                'integer',
                'min:' . self::QR_TTL_MIN,
                'max:' . self::QR_TTL_MAX,
            ],
        ]);

        Setting::set(self::QR_TTL_KEY, (string) $data['qr_ttl_seconds']);

        return response()->json([
            'status' => 0,
            'message' => 'Tiempo de vida del QR actualizado.',
            'data' => ['qr_ttl_seconds' => $data['qr_ttl_seconds']],
        ], 200);
    }
}
