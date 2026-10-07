<?php

namespace App\Services\Qr;

use App\Models\Setting;

/** Tiempo de vida del QR que ve la persona antes de que expire (configurable por administración). */
class QrSettingsService
{
    public const TTL_DEFAULT_SECONDS = 300;

    public const TTL_MIN_SECONDS = 30;

    public const TTL_MAX_SECONDS = 3600;

    private const TTL_KEY = 'qr_ttl_seconds';

    public function ttlSeconds(): int
    {
        return (int) Setting::get(self::TTL_KEY, (string) self::TTL_DEFAULT_SECONDS);
    }

    public function saveTtlSeconds(int $seconds): void
    {
        Setting::set(self::TTL_KEY, (string) $seconds);
    }
}
