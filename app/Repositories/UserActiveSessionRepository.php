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

}