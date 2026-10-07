<?php

namespace App\Repositories;

use App\Models\UserActiveSession;

class UserActiveSessionRepository
{
    public function create(
        int $userId,
        string $sessionId,
        string $platform,
        string $deviceName,
        string $ipAddress,
        string $userAgent,
    ): UserActiveSession {
        return UserActiveSession::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'platform' => $platform,
            'device_name' => $deviceName,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    public function find(int $userId, string $sessionId): ?UserActiveSession
    {
        return UserActiveSession::where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->first();
    }

    /**
     * Deja solo la sesión $keepSessionId de esa aplicación: una cuenta tiene
     * como máximo una sesión web y una móvil. También se eliminan las sesiones
     * antiguas sin aplicación registrada.
     */
    public function keepOnly(int $userId, string $platform, string $keepSessionId): void
    {
        UserActiveSession::where('user_id', $userId)
            ->where('session_id', '!=', $keepSessionId)
            ->where(fn ($query) => $query->where('platform', $platform)->orWhereNull('platform'))
            ->delete();
    }

    public function delete(int $userId, string $sessionId): void
    {
        UserActiveSession::where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->delete();
    }

    /** Cierra todas las sesiones de la cuenta, en cualquier aplicación. */
    public function deleteAllForUser(int $userId): void
    {
        UserActiveSession::where('user_id', $userId)->delete();
    }

    /** Cierra las sesiones de la cuenta en una aplicación (y las antiguas sin aplicación). */
    public function deleteForPlatform(int $userId, string $platform): void
    {
        UserActiveSession::where('user_id', $userId)
            ->where(fn ($query) => $query->where('platform', $platform)->orWhereNull('platform'))
            ->delete();
    }
}
