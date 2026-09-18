<?php 
namespace App\Repositories;

use App\Models\UserActiveSession;

class UserActiveSessionRepository
{
    public function createSession(
        int $userId,
        String $sessionId,
        String $deviceName,
        String $ipAddress,
        String $userAgent,)
    {
        return UserActiveSession::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'device_name' => $deviceName,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    //Conserva solo las $limit sesiones usadas más recientemente y elimina el resto
    //(se llama justo después de crear la sesión nueva, así que esta siempre se conserva)
    public function enforceSessionLimit(int $userId, int $limit): void
    {
        // Se desempata por id porque la columna updated_at se guarda con
        // precisión de segundos y varias sesiones pueden crearse en el mismo segundo.
        $idsToKeep = UserActiveSession::where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');

        UserActiveSession::where('user_id', $userId)
            ->whereNotIn('id', $idsToKeep)
            ->delete();
    }

}