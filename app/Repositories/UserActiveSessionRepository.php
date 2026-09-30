<?php
namespace App\Repositories;

use App\Models\UserActiveSession;

class UserActiveSessionRepository
{
    public function createSession(
        int $userId,
        string $sessionId,
        string $platform,
        string $deviceName,
        string $ipAddress,
        string $userAgent,
    ) {
        return UserActiveSession::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'platform' => $platform,
            'device_name' => $deviceName,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Deja solo la sesión $keepSessionId de esa aplicación: una cuenta tiene
     * como máximo una sesión web y una móvil. También se eliminan las sesiones
     * antiguas sin aplicación registrada.
     */
    public function keepOnlySession(int $userId, string $platform, string $keepSessionId): void
    {
        UserActiveSession::where('user_id', $userId)
            ->where('session_id', '!=', $keepSessionId)
            ->where(fn ($q) => $q->where('platform', $platform)->orWhereNull('platform'))
            ->delete();
    }
}
