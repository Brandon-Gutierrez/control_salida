<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
