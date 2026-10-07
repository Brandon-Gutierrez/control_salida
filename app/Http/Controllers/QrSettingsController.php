<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateQrSettingsRequest;
use App\Services\Qr\QrSettingsService;
use Illuminate\Http\JsonResponse;

class QrSettingsController extends Controller
{
    public function __construct(private QrSettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'data' => [
                'qr_ttl_seconds' => $this->settings->ttlSeconds(),
                'qr_ttl_seconds_min' => QrSettingsService::TTL_MIN_SECONDS,
                'qr_ttl_seconds_max' => QrSettingsService::TTL_MAX_SECONDS,
            ],
        ]);
    }

    public function update(UpdateQrSettingsRequest $request): JsonResponse
    {
        $seconds = $request->validated('qr_ttl_seconds');

        $this->settings->saveTtlSeconds($seconds);

        return response()->json([
            'status' => 0,
            'message' => 'Tiempo de vida del QR actualizado.',
            'data' => ['qr_ttl_seconds' => $seconds],
        ]);
    }
}
